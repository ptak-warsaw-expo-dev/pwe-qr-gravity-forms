# PWE QR Gravity Forms

PWE QR Gravity Forms is a WordPress plugin for generating QR codes for Gravity Forms entries and notifications.

The plugin allows you to create QR code feeds inside Gravity Forms, use QR codes in notification messages, attach generated QR images to emails, and save the generated QR image URL into Gravity Forms entry metadata.

---

## Features

- Creates custom QR code feeds for Gravity Forms
- Generates unique QR code values based on form ID, entry ID, domain prefix, and a random feed value
- Supports QR code labels
- Supports custom QR code size
- Supports optional logo overlay in the center of the QR code
- Supports multiple shortcode formats (standard and curly-brace)
- Allows QR codes to be used inside Gravity Forms notifications
- Allows QR code images to be attached to notification emails
- Saves the generated QR image URL into Gravity Forms entry metadata
- Duplicates QR feeds when a Gravity Form is duplicated
- Supports private GitHub-based plugin updates

---

## Requirements

- WordPress
- Gravity Forms 2.5 or newer
- PHP GD extension
- PHP QR Code library (included)
- Plugin Update Checker library (if GitHub updates are used)

---

## Installation

1. Upload the plugin folder to:

```text
wp-content/plugins/pwe-qr-gravity-forms
```

2. Activate the plugin in the WordPress admin panel  
3. Make sure Gravity Forms is installed and active

---

## Usage

After activation, open a Gravity Forms form and go to:

```text
Settings → PWE QR
```

Create a new QR feed and configure:

- Feed Name
- QR Code Label
- QR Code Size
- Logo URL

The **Feed Name** is required for all shortcodes and merge tags.

---

## QR Shortcodes & Merge Tags

The plugin supports two formats:

- Shortcodes → for notification message content  
- Curly-brace tags → for links and HTML attributes  

---

### Standard Shortcodes (Notification Messages)

Display QR code as image:

```text
[pwe_qr_img name="your_feed_name"]
```

Display QR image URL:

```text
[pwe_qr_url name="your_feed_name"]
```

Optional size:

```text
[pwe_qr_img name="your_feed_name" size="150"]
[pwe_qr_url name="your_feed_name" size="150"]
```

---

### Curly-Brace Format (Links & HTML)

QR image URL:

```text
{pwe_qr_url name=your_feed_name}
```

Encoded QR URL (for use inside another URL):

```text
{pwe_qr_url_encoded name=your_feed_name}
```

QR image:

```text
{pwe_qr_img name=your_feed_name}
```

Optional size:

```text
{pwe_qr_img name=your_feed_name size=150}
{pwe_qr_url name=your_feed_name size=150}
{pwe_qr_url_encoded name=your_feed_name size=150}
```

---

### Examples

Simple QR link:

```markdown
[Open QR code]({pwe_qr_url name=your_feed_name})
```

QR inside external URL (encoded):

```html
<a href="https://warsawexpo.eu/assets/badge/local/loading.html?category=YOUR_CATEGORY&getname=YOUR_NAME&firma=YOUR_COMPANY&qrcode={pwe_qr_url_encoded name=your_feed_name}">
    Generate badge
</a>
```

---

### Important

The value of `name=` must exactly match the **Feed Name** configured in Gravity Forms.

---

## Email Attachment

In the Gravity Forms notification settings, enable:

```text
Add a QR-Code as Image to the Notification
```

When enabled, the generated QR code will be attached to the email as a PNG file.

---

## Entry Metadata

After form submission, the plugin saves the QR image URL into entry metadata.

Meta key:

```text
pwe_qr_code_url
```

Example value:

```text
https://example.com/?pwe_qr_img=1&value=...&label=...&size=200&sig=...
```

Stored in:

```text
wp_gf_entry_meta
```

---

## QR Code URL Rendering

QR codes are generated dynamically via signed URLs.

The plugin uses an HMAC signature to validate requests before generating the image.

Example:

```text
https://example.com/?pwe_qr_img=1&value=...&label=...&size=200&sig=...
```

---

## Form Duplication

When duplicating a Gravity Form:

- QR feeds are duplicated automatically
- Feed name and random value are preserved
- Form-based prefix is regenerated

---

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

---

## Main Classes

- **PWE_QR_Gravity_Forms** – Initializes all core components  
- **PWE_GF_QR_Addon** – Handles feed configuration and duplication  
- **PWE_QR_Generator** – Generates QR values and images  
- **PWE_QR_Notifications** – Processes shortcodes and email attachments  
- **PWE_QR_Entry_Meta** – Stores QR URL in entry metadata  
- **PWE_QR_Image_Controller** – Handles signed URL validation and image output  
- **PWE_QR_Updater** – Handles GitHub-based updates  

---

## License

GPL v2 or later

---

## Author

Anton Melnychuk