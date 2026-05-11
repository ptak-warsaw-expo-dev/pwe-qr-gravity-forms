# PWE QR Gravity Forms

PWE QR Gravity Forms is a WordPress plugin for generating QR codes for Gravity Forms entries and notifications.

The plugin allows you to create QR code feeds inside Gravity Forms, use QR codes in notification messages, attach generated QR images to emails, and save the generated QR image URL into Gravity Forms entry metadata.

## Features

- Creates custom QR code feeds for Gravity Forms.
- Generates unique QR code values based on form ID, entry ID, domain prefix, and a random feed value.
- Supports QR code labels.
- Supports custom QR code size.
- Supports optional logo overlay in the center of the QR code.
- Allows QR codes to be used inside Gravity Forms notifications with a shortcode.
- Allows QR code images to be attached to notification emails.
- Saves the generated QR image URL into Gravity Forms entry metadata.
- Duplicates QR feeds when a Gravity Form is duplicated.
- Supports private GitHub-based plugin updates.

## Requirements

- WordPress
- Gravity Forms 2.5 or newer
- PHP GD extension
- PHP QR Code library included in the plugin
- Plugin Update Checker library, if GitHub updates are used

## Installation

1. Upload the plugin folder to:

   ```text
   wp-content/plugins/pwe-qr-gravity-forms
   ```

2. Activate the plugin in the WordPress admin panel.
3. Make sure Gravity Forms is installed and active.

## Usage

After activation, open a Gravity Forms form and go to the form settings.

Create a new feed under:

```text
Settings → PWE QR
```

Configure the QR feed:

- Feed Name
- QR Code Label
- QR Code Size
- Logo URL

The feed name is used later in the notification shortcode.

## Notification Shortcode

Use the following shortcode inside a Gravity Forms notification message:

```text
[pwe_qr name="your_feed_name"]
```

Example:

```text
[pwe_qr name="visitor_qr"]
```

You can also pass a size value:

```text
[pwe_qr name="visitor_qr" size="300"]
```

The shortcode will be replaced with a QR code image in the notification message.

## Email Attachment

In the Gravity Forms notification settings, enable:

```text
Add a QR-Code as Image to the Notification
```

When enabled, the generated QR code will also be attached to the outgoing email as a PNG image.

## Entry Metadata

After form submission, the plugin saves the generated QR image URL into Gravity Forms entry metadata.

The metadata key is:

```text
pwe_qr_code_url
```

Example value:

```text
https://example.com/?pwe_qr_img=1&value=...&label=...&size=200&sig=...
```

The data is stored in the Gravity Forms entry meta table, usually:

```text
wp_gf_entry_meta
```

## QR Code URL Rendering

QR code images are rendered dynamically through a signed URL.

The plugin uses an HMAC signature to validate QR image requests before generating the PNG output.

Example URL structure:

```text
https://example.com/?pwe_qr_img=1&value=...&label=...&size=200&sig=...
```

## Form Duplication

When a Gravity Form is duplicated, the plugin also duplicates existing QR feeds for the new form.

The QR feed name and random part are preserved, while the form-based prefix is rebuilt for the new form ID.

## Plugin Structure

```text
pwe-qr-gravity-forms/
├── pwe-qr-gravity-forms.php
├── includes/
│   ├── class-pwe-qr-gravity-forms.php
│   ├── class-pwe-qr-generator.php
│   ├── class-pwe-gf-addon.php
│   ├── class-pwe-qr-notifications.php
│   ├── class-pwe-qr-entry-meta.php
│   ├── class-pwe-qr-image-controller.php
│   └── class-pwe-qr-updater.php
├── phpqrcode/
├── plugin-update-checker/
└── assets/
```

## Main Classes

### PWE_QR_Gravity_Forms

Main plugin bootstrap class. Initializes the QR generator, notification handler, image controller, entry meta handler, updater, and Gravity Forms add-on registration.

### PWE_GF_QR_Addon

Gravity Forms Feed Add-On class. Handles QR feed settings, feed list display, QR structure generation, and QR feed duplication when forms are duplicated.

### PWE_QR_Generator

Generates QR code values and PNG images. Supports labels and optional logo overlay.

### PWE_QR_Notifications

Handles QR shortcodes inside Gravity Forms notifications and optional QR email attachments.

### PWE_QR_Entry_Meta

Saves the generated QR image URL into Gravity Forms entry metadata after submission.

### PWE_QR_Image_Controller

Builds signed QR image URLs and renders QR PNG images from validated requests.

### PWE_QR_Updater

Handles GitHub-based plugin updates using Plugin Update Checker.

## Shortcode Reference

```text
[pwe_qr name="feed_name"]
```

Required attributes:

| Attribute | Description |
|---|---|
| name | Name of the QR feed configured in Gravity Forms |

Optional attributes:

| Attribute | Description |
|---|---|
| size | QR code size in pixels |

Example:

```text
[pwe_qr name="visitor_qr" size="300"]
```

## Metadata Reference

| Meta key | Description |
|---|---|
| pwe_qr_code_url | Generated QR image URL for the submitted entry |

## License

GPL v2 or later.

## Author

Anton Melnychuk
