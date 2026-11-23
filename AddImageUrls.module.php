<?php namespace ProcessWire;

class AddImageUrls extends WireData implements Module, ConfigurableModule {

	/**
	 * Construct
	 */
	public function __construct() {
		parent::__construct();
		$this->mime_types = <<<EOT
text/plain:txt
application/pdf:pdf
application/msword:docx
application/excel:xlsx
application/rtf:rtf
image/gif:gif
image/jpeg:jpg
image/png:png
image/svg+xml:svg
image/webp:webp
EOT;
	}

	/**
	 * Ready
	 */
	public function ready() {
		$this->addHookMethod('Pagefiles::addFromUrl', $this, 'addFromUrl');
		$this->addHookAfter('InputfieldFile::render', $this, 'modifyInputfield');
		$this->addHookBefore('ProcessPageEdit::execute', $this, 'addDependencies');
		$this->addHookBefore('ProcessPageEdit::processInput', $this, 'processInput');
	}

	/**
	 * Modify inputfield
	 *
	 * @param HookEvent $event
	 */
	protected function modifyInputfield(HookEvent $event) {
		// Only if process is an instance of WirePageEditor, but not ProcessProfile or ListerPro
		$process = $this->wire()->process;
		if($process instanceof ProcessProfile) return;
		if($process instanceof ProcessPageListerPro) return;
		if(!$process instanceof WirePageEditor) return;

		/** @var InputfieldImage $inputfield */
		$inputfield = $event->object;
		$is_image_inputfield = $inputfield instanceof InputfieldImage;
		$out = $event->return;
		$page = $inputfield->hasPage;
		$field = $inputfield->hasField;
		if(!$page || !$page->id) return;
		if(!$field) return;
		// Exclude combo
		if($inputfield->wrapAttr('data-combo-name') || $inputfield->wrapAttr('data-combo-num')) return;

		if($this->always_show_field) $inputfield->addClass('aiu-always-visible', 'wrapClass');

		$button_text = $this->_('Paste URLs');
		if($is_image_inputfield) {
			$placeholder_text = $this->_('Paste URLs to images here, one per line…');
		} else {
			$placeholder_text = $this->_('Paste URLs to files here, one per line…');
		}

		$button = "<button class='url-upload-toggle ui-button ui-widget ui-corner-all ui-state-default ui-priority-secondary'><span class='ui-button-text'><i class='fa fa-clipboard'></i> $button_text</span></button>";
		$find = array("<span class='InputfieldImageValidExtensions", "<span class='InputfieldFileValidExtensions");
		$replace = array("$button<span class='InputfieldImageValidExtensions", "$button<span class='InputfieldFileValidExtensions");
		$out = str_replace($find, $replace, $out);
		$out .= "
<div class='url-upload-container'>
<textarea name='urlUpload*{$field->name}*{$page->id}' rows='2' placeholder='$placeholder_text'></textarea>
</div>
";
		$event->return = $out;
	}

	/**
	 * Add JS and CSS dependencies
	 *
	 * @param HookEvent $event
	 */
	protected function addDependencies(HookEvent $event) {
		$config = $this->wire()->config;

		// Return if ProcessPageEdit is being loaded via AJAX
		if($config->ajax) return;

		// Add JS and CSS dependencies
		$info = $this->wire()->modules->getModuleInfo($this);
		$version = $info['version'];
		$config->scripts->add($config->urls->{$this} . "{$this}.js?v={$version}");
		$config->styles->add($config->urls->{$this} . "{$this}.css?v={$version}");
	}

	/**
	 * Process URLs in $input
	 *
	 * @param HookEvent $event
	 */
	protected function processInput(HookEvent $event) {

		/** @var InputfieldForm $form */
		$form = $event->arguments(0);
		// Only for main Page Edit form
		if($form->name !== 'ProcessPageEdit') return;

		foreach($this->wire()->input->post as $key => $value) {

			// Ignore unrelated input
			if(substr($key, 0, 9) !== 'urlUpload' || empty($value)) continue;

			// Get variables from key name
			list($junk, $field_name, $page_id) = explode('*', $key);
			$field_name = $this->wire()->sanitizer->fieldName($field_name);
			$page = $this->wire()->pages->get((int) $page_id);
			$field = $this->wire()->fields->get($field_name);

			// Skip if the user does not have edit access for the page and field
			$editable = false;
			if($page instanceof RepeaterPage) {
				// Check field and Repeater field are editable
				if(!$field->useRoles || $field->editable()) {
					$repeater_field = $page->getForField();
					if(!$repeater_field->useRoles || $repeater_field->editable()) $editable = true;
				}
			} else {
				// Check page and field are editable
				if($page->editable($field_name)) $editable = true;
			}
			if(!$editable) {
				$this->error( $this->_('You do not have permission to edit this field.') );
				continue;
			}

			// Get array of URLs
			$urls = preg_split("/\r\n|\n|\r/", $value);

			// Add URLs to field
			$this->addUrlsToField($urls, $page, $field);
		}
	}

	/**
	 * Add image(s) from URL(s) using the API
	 *
	 * The argument is expected to be either:
	 * - a string with a URL "https://domain.com/image.jpg"
	 * - an array of URLs ["https://domain.com/image1.jpg", "https://domain.com/image2.jpg"]
	 *
	 * @param HookEvent $event
	 * @throws WireException
	 */
	protected function addFromUrl(HookEvent $event) {

		$urls = $event->arguments(0);
		if(empty($urls)) return;

		$page = $event->object->getPage();
		$field = $event->object->getField();

		// Convert to an array
		if(is_string($urls)) {
			$urls = [trim($urls)];
		} elseif(!is_array($urls)) {
			// if the URLs turns out to be neither a string nor an array, abort
			throw new WireException("Unexpected format");
		}

		$this->addUrlsToField($urls, $page, $field, true);
	}

	/**
	 * Convert URL(s) to Pagefile (or Pageimage) and add it to the field
	 *
	 * @param array $urls Array of URLs
	 * @param Page $page Page holding the field
	 * @param Field $field Field to add the file (image) to
	 * @param bool $from_api If from API log errors instead of showing admin notices
	 */
	protected function ___addUrlsToField($urls, $page, $field, $from_api = false) {

		$modules = $this->wire()->modules;
		/** @var Pageimages $field_value */
		$field_value = $page->getUnformatted($field->name);

		// How many more uploads allowed?
		if($field->maxFiles == 1) {
			$remaining_uploads = 1; // Single image/file field is allowed to be overwritten
		} elseif($field->maxFiles) {
			$remaining_uploads = $field->maxFiles - count($field_value);
		} else {
			$remaining_uploads = 9999;
		}

		// Determine allowed extensions
		$allowed_extensions = $this->getAllowedExtensions($field);

		$page->of(false);
		foreach($urls as $url) {
			$url = trim($url);
			// Break if the field is full
			if($remaining_uploads < 1) {
				$message = sprintf($this->_('Max file upload limit reached for field "%s".'), $field->name);
				if($from_api) {
					$this->wire()->log->warning($message);
				} else {
					$this->warning($message);
				}
				break;
			}
			// Must be a valid URL
			if(!filter_var($url, FILTER_VALIDATE_URL)) {
				$message = sprintf($this->_('%s is not a valid URL.'), $url);
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				continue;
			}
			// Validate file extension if one exists
			$parsed_url = parse_url($url);
			if(empty($parsed_url['path'])) {
				$message = sprintf($this->_('The URL %s has no parsable path.'), $url);
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				continue;
			}
			$path_parts = pathinfo($parsed_url['path']);
			if(empty($path_parts['basename'])) {
				$message = sprintf($this->_('The URL %s has no parsable basename.'), $url);
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				continue;
			}
			$extension = isset($path_parts['extension']) ? $path_parts['extension'] : '';
			$extension = strtolower($extension);
			if($extension && !in_array($extension, $allowed_extensions)) {
				$message = sprintf($this->_('%s is not an allowed extension for field "%s".'), $extension, $field->name);
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				continue;
			}

			// Download file to temp directory
			$files = $this->wire()->files;
			$td = $files->tempDir($this->className);
			$td_path = (string) $td;
			$destination = $td_path . $path_parts['basename'];
			$error = $this->downloadUrl($url, $destination);
			if($error !== '') {
				$message = sprintf($this->_('No file could be downloaded from URL %s'), $url) . " ($error)";
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				continue;
			}
			$mime_type = mime_content_type($destination);
			$mime_mappings = [];
			$mapping_lines = preg_split('/[\r\n]+/', $this->mime_types);
			foreach($mapping_lines as $line) {
				$pieces = explode(':', $line, 2);
				if(count($pieces) < 2) continue;
				$mime_mappings[$pieces[0]] = $pieces[1];
			}
			// Reject if MIME type doesn't correspond to an allowed extension
			$mime_extension = isset($mime_mappings[$mime_type]) ? $mime_mappings[$mime_type] : '';
			if(!in_array($mime_extension, $allowed_extensions)) {
				$message = sprintf($this->_('The MIME type "%s" of remote file %s does not correspond with a valid file extension for field "%s".'), $mime_type, $url, $field->name);
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				continue;
			}
			// Change URL to local downloaded file
			$original_url = $url;
			$url = $destination;
			// If no file extension, add extension based on the MIME type
			if(!$extension) {
				$url .= ".$mime_extension";
				$files->rename($destination, $url);
			}

			// Convert WebP to JPG if WebpToJpg module is installed
			if($extension === 'webp' || $mime_extension === 'webp') {
				if($modules->isInstalled('WebpToJpg')) {
					$webp_to_jpg = $modules->get('WebpToJpg');
					$url = $webp_to_jpg->convertToWebp($url);
				}
			}

			// Run any installed FileValidator modules on the downloaded file, as happens for a
			// normal upload. Validators such as FileValidatorSvgSanitizer may sanitize the file
			// in place (valid) or reject it (false). Null means no validator applies.
			$sanitizer = $this->wire()->sanitizer;
			$valid = $sanitizer->validateFile($url, ['page' => $page, 'field' => $field]);
			if($valid === false) {
				$errors = $sanitizer->errors('clear array');
				$message = sprintf($this->_('The file from URL %s failed validation.'), $original_url);
				if(count($errors)) $message .= ' ' . implode(' ', $errors);
				if($from_api) {
					$this->wire()->log->error($message);
				} else {
					$this->error($message);
				}
				$files->unlink($url);
				continue;
			}

			// If it's a single image/file field and there's an existing image/file, remove it (as per the core upload behaviour)
			if($field->maxFiles == 1 && count($field_value)) $field_value->removeAll();
			// Create Pagefile
			if($field->type instanceof FieldtypeImage) {
				$pagefile = new Pageimage($field_value, $url);
				// Resize to maximum width/height if necessary
				if($field->maxWidth && $field->maxWidth < $pagefile->width || $field->maxHeight && $field->maxHeight < $pagefile->height) {
					$width = $field->maxWidth ?: 0;
					$height = $field->maxHeight ?: 0;
					if($width && $pagefile->width >= $pagefile->height) $height = 0;
					if($height && $pagefile->width < $pagefile->height) $width = 0;
					$sizer = new ImageSizer($pagefile->filename);
					$sizer->setUpscaling(false);
					$sizer->resize($width, $height);
				}
			} else {
				$pagefile = new Pagefile($field_value, $url);
			}

			// Add the Pagefile to the field
			$field_value->add($pagefile);
			// Decrement remaining uploads
			$remaining_uploads--;
		}

		// Save
		$page->save($field);
	}

	/**
	 * Get the file extensions allowed for a field
	 *
	 * Uses the same list as the core file inputfield where available (3.0.167+), so an
	 * extension that needs a FileValidator module (e.g. svg) is only allowed when a
	 * validator is installed or the extension was whitelisted in the field settings.
	 *
	 * @param Field $field
	 * @return array
	 */
	protected function getAllowedExtensions($field) {
		$fieldtype = $field->type;
		if($fieldtype && method_exists($fieldtype, 'getValidFileExtensions')) {
			$info = $fieldtype->getValidFileExtensions($field);
			return array_values($info['valid']);
		}
		$extensions = trim(str_replace(["\n", ',', '.'], ' ', strtolower((string) $field->extensions)));
		return array_values(array_filter(explode(' ', $extensions), 'strlen'));
	}

	/**
	 * Download a URL to a local file
	 *
	 * Uses WireHttp, which tries curl first and falls back to fopen and then sockets, so
	 * it does not depend on allow_url_fopen, and it reports why a download failed.
	 *
	 * Redirects are followed here rather than by WireHttp so that every URL in the chain
	 * can be checked with checkUrl() before it is requested.
	 *
	 * @param string $url
	 * @param string $destination Full path of the file to write
	 * @return string Empty string on success, otherwise the reason for the failure
	 */
	protected function downloadUrl($url, $destination) {
		$max_redirects = 5;
		for($num_redirects = 0; $num_redirects <= $max_redirects; $num_redirects++) {
			$ip = '';
			$error = $this->checkUrl($url, $ip);
			if($error !== '') return $error;

			$http = new WireHttp();
			$this->wire($http);
			$location = '';
			$curl_setopt = [];
			// Do not let any download method follow redirects on its own
			$options = [
				'followRedirects' => false, // socket method
				'follow_location' => 0, // fopen method
			];
			if(defined('CURLOPT_FOLLOWLOCATION')) {
				$curl_setopt[CURLOPT_FOLLOWLOCATION] = false;
				// WireHttp does not record response headers for curl downloads, so get the
				// redirect location here
				$curl_setopt[CURLOPT_HEADERFUNCTION] = function($curl, $header) use(&$location) {
					if(stripos($header, 'location:') === 0) $location = trim(substr($header, 9));
					return strlen($header);
				};
				// Connect to the address that was checked, so that the host cannot resolve
				// to a different address by the time the request is made
				$parsed_url = parse_url($url);
				$port = isset($parsed_url['port']) ? (int) $parsed_url['port'] : (strtolower($parsed_url['scheme']) === 'https' ? 443 : 80);
				$host = trim($parsed_url['host'], '[]');
				if(!filter_var($host, FILTER_VALIDATE_IP)) {
					$address = strpos($ip, ':') !== false ? "[$ip]" : $ip;
					$curl_setopt[CURLOPT_RESOLVE] = ["$host:$port:$address"];
				}
			}
			if($this->user_agent) {
				// WireHttp::download() does not send headers added with setHeader(), so the
				// user agent is passed as an option instead: "user_agent" is used by the fopen
				// method and CURLOPT_USERAGENT by the curl method
				$options['user_agent'] = $this->user_agent;
				if(defined('CURLOPT_USERAGENT')) $curl_setopt[CURLOPT_USERAGENT] = $this->user_agent;
			}
			if(count($curl_setopt)) $options['curl_setopt'] = $curl_setopt;
			try {
				$http->download($url, $destination, $options);
			} catch(\Exception $e) {
				// An HTTP status says it best, e.g. "404 Not Found"
				if($http->getHttpCode() >= 400) return $http->getHttpCode(true);
				// Otherwise the last underlying error, without PHP's "2: " prefix
				$errors = $http->getError(true);
				$error = $errors ? (string) end($errors) : $e->getMessage();
				return preg_replace('/^\d+:\s*/', '', trim(preg_replace('/\s+/', ' ', $error)));
			}
			if(!in_array((int) $http->getHttpCode(), [301, 302, 303, 307, 308], true)) return '';
			// Redirect: check the new location on the next pass
			if($location === '') $location = trim((string) $http->getResponseHeader('location'));
			if($location === '') return $this->_('Redirect without a location');
			$url = $this->resolveUrl($url, $location);
		}
		return $this->_('Too many redirects');
	}

	/**
	 * Check that a URL may be downloaded
	 *
	 * Only http and https URLs are allowed, and the host must resolve only to public
	 * addresses, so that pasted URLs cannot be used to make requests to the server itself,
	 * the local network or cloud metadata services.
	 *
	 * @param string $url
	 * @param string $ip Populated with the address the download should connect to
	 * @return string Empty string if the URL is allowed, otherwise the reason it is not
	 */
	protected function checkUrl($url, &$ip = '') {
		$parsed_url = parse_url($url);
		$scheme = isset($parsed_url['scheme']) ? strtolower($parsed_url['scheme']) : '';
		if($scheme !== 'http' && $scheme !== 'https') return $this->_('Only http and https URLs are allowed');
		$host = isset($parsed_url['host']) ? trim($parsed_url['host'], '[]') : '';
		if($host === '') return $this->_('The URL has no host');
		$ips = $this->resolveHost($host);
		if(!count($ips)) return $this->_('The host could not be resolved');
		if(!$this->isAllowedHost($host, $ips)) return $this->_('The host resolves to an address that is not allowed');
		$ip = reset($ips);
		return '';
	}

	/**
	 * Is the given host allowed as a download source?
	 *
	 * Hook after this method to allow other hosts, e.g. an intranet image server:
	 *
	 * ```php
	 * $wire->addHookAfter('AddImageUrls::isAllowedHost', function(HookEvent $event) {
	 *     if($event->arguments(0) === 'images.intranet.local') $event->return = true;
	 * });
	 * ```
	 *
	 * @param string $host Host name or IP address from the URL
	 * @param array $ips Addresses the host resolves to
	 * @return bool True if every address is a public address
	 */
	protected function ___isAllowedHost($host, array $ips) {
		foreach($ips as $ip) {
			if(!$this->isPublicIp($ip)) return false;
		}
		return true;
	}

	/**
	 * Get the IPv4 and IPv6 addresses a host resolves to
	 *
	 * @param string $host
	 * @return array
	 */
	protected function resolveHost($host) {
		if(filter_var($host, FILTER_VALIDATE_IP)) return [$host];
		$ips = gethostbynamel($host);
		if(!is_array($ips)) $ips = [];
		if(function_exists('dns_get_record') && defined('DNS_AAAA')) {
			$records = @dns_get_record($host, DNS_AAAA);
			if(is_array($records)) {
				foreach($records as $record) {
					if(!empty($record['ipv6'])) $ips[] = $record['ipv6'];
				}
			}
		}
		return array_values(array_unique($ips));
	}

	/**
	 * Is the given IP address a public address?
	 *
	 * Rejects loopback, private, link-local, carrier-grade NAT, multicast and other
	 * reserved ranges, including IPv4 addresses embedded in IPv6 addresses.
	 *
	 * @param string $ip
	 * @return bool
	 */
	protected function isPublicIp($ip) {
		$packed = @inet_pton($ip);
		if($packed === false) return false;
		if(strlen($packed) === 16) {
			// IPv4-mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96): check the embedded IPv4 address
			if($this->ipInRange($packed, '::ffff:0:0', 96) || $this->ipInRange($packed, '64:ff9b::', 96)) {
				return $this->isPublicIp(inet_ntop(substr($packed, 12)));
			}
			// 6to4 (2002::/16): check the embedded IPv4 address
			if($this->ipInRange($packed, '2002::', 16)) {
				return $this->isPublicIp(inet_ntop(substr($packed, 2, 4)));
			}
			$ranges = [
				'::' => 96, // unspecified, loopback and IPv4-compatible
				'100::' => 64, // discard
				'2001:db8::' => 32, // documentation
				'fc00::' => 7, // unique local
				'fe80::' => 10, // link-local
				'fec0::' => 10, // site-local (deprecated)
				'ff00::' => 8, // multicast
			];
		} else {
			$ranges = [
				'0.0.0.0' => 8, // "this" network
				'10.0.0.0' => 8, // private
				'100.64.0.0' => 10, // carrier-grade NAT
				'127.0.0.0' => 8, // loopback
				'169.254.0.0' => 16, // link-local, including cloud metadata services
				'172.16.0.0' => 12, // private
				'192.0.0.0' => 24, // IETF protocol assignments
				'192.0.2.0' => 24, // documentation
				'192.168.0.0' => 16, // private
				'198.18.0.0' => 15, // benchmarking
				'198.51.100.0' => 24, // documentation
				'203.0.113.0' => 24, // documentation
				'224.0.0.0' => 4, // multicast
				'240.0.0.0' => 4, // reserved, including broadcast
			];
		}
		foreach($ranges as $network => $bits) {
			if($this->ipInRange($packed, $network, $bits)) return false;
		}
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	/**
	 * Is the packed IP address within the given network?
	 *
	 * @param string $packed Address from inet_pton()
	 * @param string $network Network address, e.g. "10.0.0.0"
	 * @param int $bits Prefix length
	 * @return bool
	 */
	protected function ipInRange($packed, $network, $bits) {
		$network = inet_pton($network);
		if(strlen($network) !== strlen($packed)) return false;
		$bytes = (int) floor($bits / 8);
		if(substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) return false;
		$remainder = $bits % 8;
		if(!$remainder) return true;
		$mask = 0xff << (8 - $remainder) & 0xff;
		return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
	}

	/**
	 * Resolve a redirect location against the URL that returned it
	 *
	 * @param string $base
	 * @param string $location
	 * @return string
	 */
	protected function resolveUrl($base, $location) {
		if(preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) return $location;
		$parsed_url = parse_url($base);
		$origin = $parsed_url['scheme'] . '://';
		if(strpos($location, '//') === 0) return $parsed_url['scheme'] . ':' . $location;
		$origin .= $parsed_url['host'];
		if(isset($parsed_url['port'])) $origin .= ':' . $parsed_url['port'];
		if(strpos($location, '/') === 0) return $origin . $location;
		$path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
		$dir = substr($path, 0, strrpos($path, '/') + 1);
		return $origin . $dir . $location;
	}

	/**
	 * Config inputfields
	 *
	 * @param InputfieldWrapper $inputfields
	 */
	public function getModuleConfigInputfields($inputfields) {
		$modules = $this->wire()->modules;

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f_name = 'mime_types';
		$f->name = $f_name;
		$f->label = $this->_('MIME types');
		$f->description = $this->_('Enter MIME type > file extension mappings in the format "MIME type:file extension", one per line. File extensions should be lower case. These mappings are used when validating URLs to files that do not have file extensions.');
		$f->value = $this->$f_name;
		$inputfields->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f_name = 'user_agent';
		$f->name = $f_name;
		$f->label = $this->_('User agent');
		$f->description = $this->_('For websites that require a User-Agent header to be set, e.g. [Wikimedia](https://foundation.wikimedia.org/wiki/Policy:User-Agent_policy).');
		$f->value = $this->$f_name;
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f_name = 'always_show_field';
		$f->name = $f_name;
		$f->label = $this->_('Always show URLs field');
		$f->description = $this->_('When this option is checked the URLs field will be permanently visible instead of revealed when the "Paste URLs" button is clicked.');
		$f->checked = $this->$f_name === 1 ? 'checked' : '';
		$inputfields->add($f);

	}

}
