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
	protected function addUrlsToField($urls, $page, $field, $from_api = false) {

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
		$allowed_extensions = explode(' ', $field->extensions);

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
			if($this->user_agent) ini_set('user_agent', $this->user_agent);
			$success = @copy($url, $destination);
			if(!$success) {
				$message = sprintf($this->_('No file could be downloaded from URL %s'), $url);
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
