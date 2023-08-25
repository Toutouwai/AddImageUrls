# Add Image URLs

A module for ProcessWire CMS/CMF. Allows images/files to be added to Image/File fields by pasting URLs or using the API.

![screencast](https://user-images.githubusercontent.com/1538852/72048850-96323980-3322-11ea-8347-3d10a0da5b08.gif)

## Installation

[Install](http://modules.processwire.com/install-uninstall/) the Add Image URLs module.

## Configuration

You can add MIME type > file extension mappings in the module config. These mappings are used when validating URLs to files that do not have file extensions.

## Usage

A "Paste URLs" button will be added to all Image and File fields. Use the button to show a textarea where URLs may be pasted, one per line. Images/files are added when the page is saved.

A `Pagefiles::addFromUrl` method is also added to the API to achieve the same result. The argument of this method is expected to be either:
- a URL: "https://domain.com/image.jpg"
- an array of URLs: ["https://domain.com/image1.jpg", "https://domain.com/image2.jpg"]

Example:
```php
// Get unformatted value of File/Image field to be sure that it's an instance of Pagefiles
$page->getUnformatted('file_field')->addFromUrl("https://domain.com/path-to-file.ext");
// No need to call $page->save() as it's already done in the method
```

Should you have an issue using the method, please have a look at the "errors" log to check if something was wrong with your URL(s).

### WebP conversion

The core InputfieldImage does not support images in WebP format. But if you have the [WebP To Jpg](https://github.com/Toutouwai/WebpToJpg) module installed (v0.2.0 or newer) then any WebP images you add via Add Image URLs will be automatically converted to JPG format.
