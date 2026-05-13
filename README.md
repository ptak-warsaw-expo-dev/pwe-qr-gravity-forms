# PWE QR Gravity Forms

PWE QR Gravity Forms is a WordPress plugin for generating QR codes for Gravity Forms entries, notifications, and confirmation messages.

The plugin allows you to create QR code feeds inside Gravity Forms, use QR codes in notification messages, display QR codes in Gravity Forms confirmation messages, attach generated QR images to emails, and save the generated QR image URL into Gravity Forms entry metadata.

---

## Features

- Creates custom QR code feeds for Gravity Forms
- Generates unique QR code values based on form ID, entry ID, domain prefix, and a random feed value
- Supports QR code labels
- Supports custom QR code size
- Supports optional logo overlay in the center of the QR code
- Supports standard shortcode format for Gravity Forms notifications
- Supports curly-brace QR tags for Gravity Forms confirmations, links, and HTML attributes
- Allows QR codes to be used inside Gravity Forms notifications
- Allows QR codes to be used inside Gravity Forms confirmation messages
- Supports encoded QR image URLs for use as parameters inside external URLs
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

The **Feed Name** is required for all QR shortcodes and tags.

The value of `name=` must exactly match the configured **Feed Name**.

Example:

```text
Feed Name: badge
```

Correct:

```text
{pwe_qr_url_encoded name=badge}
```

Incorrect:

```text
{pwe_qr_url_encoded name=Badge}
{pwe_qr_url_encoded name=badge }
{pwe_qr_url_encoded name=badges}
```

---

## QR Shortcodes & Tags

The plugin supports two formats:

- Standard shortcodes for Gravity Forms notification messages
- Curly-brace tags for Gravity Forms confirmation messages, links, and HTML attributes

---

## Gravity Forms Notifications

QR codes can be used inside Gravity Forms notification messages.

### Display QR code as an image

```text
[pwe_qr_img name="your_feed_name"]
```

### Display QR image URL

```text
[pwe_qr_url name="your_feed_name"]
```

### Display encoded QR image URL

Use this when the QR image URL has to be placed inside another URL.

```text
[pwe_qr_url_encoded name="your_feed_name"]
```

### Optional size

```text
[pwe_qr_img name="your_feed_name" size="150"]
[pwe_qr_url name="your_feed_name" size="150"]
[pwe_qr_url_encoded name="your_feed_name" size="150"]
```

---

## Gravity Forms Confirmation Messages

QR codes can be used inside Gravity Forms confirmation messages.

This is useful when the confirmation contains a custom button, badge generator link, QR image, or another HTML element that should use the QR code generated for the submitted entry.

Inside confirmations, the plugin automatically receives the current form and entry data from Gravity Forms.

Because of that, you do **not** need to provide `form_id` or `entry_id`.

### QR image URL

```text
{pwe_qr_url name=your_feed_name}
```

### Encoded QR image URL

Use this when placing the QR image URL inside another URL as a query parameter.

```text
{pwe_qr_url_encoded name=your_feed_name}
```

### QR image

```text
{pwe_qr_img name=your_feed_name}
```

### Optional size

```text
{pwe_qr_img name=your_feed_name size=150}
{pwe_qr_url name=your_feed_name size=150}
{pwe_qr_url_encoded name=your_feed_name size=150}
```

---

## Badge Generator Example

Example confirmation button:

```html
<a 
   href="https://example.com/index.html?category={Wybierz::3}&getname={Imię i Nazwisko:1}&firma={Firma:2}&qrcode={pwe_qr_url_encoded name=badge}"
   target="_blank"
   rel="noopener">
    Wygeneruj badge
</a>
```

In this example:

```text
{pwe_qr_url_encoded name=badge}
```

is replaced with an encoded, signed QR image URL generated for the submitted entry.

The encoded format is recommended for external badge generator links because the QR image URL is passed as a parameter inside another URL.

---

## Curly-Brace Format for Links & HTML Attributes

The curly-brace format is recommended when the QR value is used inside HTML attributes, especially inside:

- `href`
- `src`
- external URL parameters such as `qrcode=...`

### Simple QR link

```html
<a href="{pwe_qr_url name=your_feed_name}">
    Open QR code
</a>
```

### QR inside external URL

```html
<a href="https://example.com/index.html?category=YOUR_CATEGORY&getname=YOUR_NAME&firma=YOUR_COMPANY&qrcode={pwe_qr_url_encoded name=your_feed_name}">
    Generate badge
</a>
```

---

## Important Notes

The value after `name=` must exactly match the **Feed Name** configured in Gravity Forms.

The plugin returns an empty value if:

- the QR feed cannot be found
- the QR feed is inactive
- the current form or entry is missing
- Gravity Forms is unavailable

QR tags in confirmations work only inside Gravity Forms confirmation processing, where Gravity Forms provides the current submitted entry.

The plugin does not register QR shortcodes for normal WordPress pages or posts.

---

## Email Attachment

In the Gravity Forms notification settings, enable:

```text
Add a QR-Code as Image to the Notification
```

When enabled, the generated QR code will be attached to the email as a PNG file.

Attachments are generated only during notification processing and are cleared after the email is sent.

---

## Entry Metadata

After form submission, the plugin saves the QR image URL into entry metadata.

Meta key:

```text
pwe_qr_code_url
```

Example value:

```text
https://example.com/?pwe_qr_img=1&value=...&label=...&size=150&sig=...
```

Stored in:

```text
wp_gf_entry_meta
```

Only the first active QR feed for the form is saved into this metadata key.

---

## QR Code URL Rendering

QR codes are generated dynamically via signed URLs.

The plugin uses an HMAC signature to validate requests before generating the image.

Example:

```text
https://example.com/?pwe_qr_img=1&value=...&label=...&size=150&sig=...
```

The signature protects QR rendering requests from being modified manually.

Supported QR image parameters:

- `value`
- `label`
- `size`
- `logo`
- `sig`

---

## Logo Overlay

A logo can be placed in the center of the QR code.

The logo path is configured in the QR feed settings using:

```text
Logo URL
```

Example:

```text
/doc/favicon-color.webp
```

Supported formats:

- WebP
- PNG
- JPG
- JPEG

The logo is resized and placed in the center of the QR code with a small white background to preserve QR scanability.

---

## Form Duplication

When duplicating a Gravity Form:

- QR feeds are duplicated automatically
- Feed name and random value are preserved
- Form-based prefix is regenerated for the new form ID
- Duplicate QR feeds with the same feed name are skipped

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
│   ├── class-pwe-qr-confirmations.php
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
- **PWE_QR_Notifications** – Processes notification confirmations and email attachments
- **PWE_QR_Confirmations** – Processes QR tags inside Gravity Forms confirmation messages
- **PWE_QR_Entry_Meta** – Stores QR URL in entry metadata
- **PWE_QR_Image_Controller** – Handles signed URL validation and image output
- **PWE_QR_Updater** – Handles GitHub-based updates

---

## License

GPL v2 or later

---

## Author

Anton Melnychuk
