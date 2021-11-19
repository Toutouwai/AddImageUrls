# Add Image URLs

A module for ProcessWire CMS/CMF. Allows images/files to be added to Image/File fields by pasting URLs or using the API.

![screencast](https://user-images.githubusercontent.com/1538852/72048850-96323980-3322-11ea-8347-3d10a0da5b08.gif)

## Installation

[Install](http://modules.processwire.com/install-uninstall/) the Add Image URLs module.

## Configuration

You can add MIME type > file extension mappings in the module config. These mappings are used when validating URLs to files that do not have file extensions.

## Usage

A "Paste URLs" button will be added to all Image and File fields. Use the button to show a textarea where URLs may be pasted, one per line. Images/files are added when the page is saved.

A `addFromUrl` method is also added to the API to achieve the same result. The argument of this method is expected to be either:
- a URL: "https://domain.com/image.jpg"
- an array of URLs: ["https://domain.com/image1.jpg", "https://domain.com/image2.jpg"]

Should you have an issue using the method, please have a look at the "errors" log to check if something is wrong with your URL(s).