# BV Listing Manager

A WordPress plugin that provides a complete front-end listing management system with WooCommerce payment integration. Users can create, pay for, and manage listings through an automated workflow.

## Overview

BV Listing Manager enables a paid listing ecosystem where users can:
- Create listings via ACF (Advanced Custom Fields) forms
- Pay for listings through WooCommerce checkout
- Manage listings (edit, hide, republish, delete) from their account dashboard
- Auto-save drafts with AJAX functionality

The plugin automates the entire workflow from draft creation to payment-verified publication.

## Features

### Listing Types
- **Osakeannit** (Share Offerings)
- **Osaketori** (Share Trading)
- **Velkakirjat** (Debt Notes)

### Listing Lifecycle
- **Draft Save**: AJAX-based auto-save with file upload support
- **Payment Processing**: Automatic cart management and checkout redirect
- **Auto-Publish**: Listings publish automatically after successful payment
- **Hide/Republish**: Paid listings can be hidden and republished without additional payment
- **90-Day Expiry**: Listings track expiration from creation date

### User Dashboard
Custom WooCommerce account endpoints:
- **My Listings**: View published listings with expiry tracking and view counts
- **Draft Listings**: Manage unpaid drafts, resume editing, or delete
- **Orders**: Purchase history
- Additional account management endpoints

### Security
- Nonce verification on all actions
- Authorization checks (author/admin only)
- Frontend access control for edit pages
- Read-only email field protection
- Input sanitization and XSS protection

## Requirements

- **WordPress** 5.0+
- **WooCommerce** 5.0+
- **Advanced Custom Fields (ACF)** Pro or Free
- **PHP** 7.2+

### Optional
- Post View Counter plugin (for view count display)

## Installation

1. Upload the `bv-listing-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Configure WooCommerce products for each listing type
4. Update product ID mappings in the plugin (see Configuration)
5. Create the required pages with appropriate shortcodes

## Configuration

### Product ID Mapping

Edit the product IDs in `bv-listing-manager.php` to match your WooCommerce products:

```php
$map = [
    'osakeanti' => 772,   // Your Osakeannit product ID
    'osaketori' => 773,   // Your Osaketori product ID
    'velkakirja' => 1722, // Your Velkakirjat product ID
];
```

### Category Slugs

Ensure these WordPress categories exist:
- `osakeannit` - for Osakeannit listings
- `osaketori` - for Osaketori listings
- `velkakirjat` - for Velkakirjat listings

### Required Pages

Create pages with these slugs:
- `/jata-ilmoitus` - Main listings page
- `/create-osakeanti/` - Osakeanti creation form
- `/create-osaketori/` - Osaketori creation form
- `/create-velkakirja/` - Velkakirja creation form
- `/edit-listing-main/` - Edit listing form (add `[bv_edit_listing]` shortcode)
- `/process-listing/` - Payment processing redirect

## Shortcodes

| Shortcode                    | Description                                             | Usage                |
|------------------------------|---------------------------------------------------------|----------------------|
| `[bv_edit_listing]`          | Renders the ACF edit form with category-specific fields | Edit listing page    |
| `[bv_edit_ad_button]`        | Displays edit button for post author/admin              | Single post template |
| `[bv_hide_republish_button]` | Shows hide or republish button based on listing state   | Single post template |

## Workflow

1. **User creates listing** via ACF form on creation page
2. **Draft saved** - listing stored as draft post
3. **User clicks payment** - redirected to `/process-listing`
4. **Cart prepared** - correct product added, previous items cleared
5. **Checkout** - standard WooCommerce checkout flow
6. **Payment confirmed** - listing automatically published
7. **User manages listing** - edit, hide, or republish from dashboard

## WooCommerce Integration

The plugin hooks into multiple WooCommerce events:
- `woocommerce_payment_complete` - Primary publish trigger
- `woocommerce_order_status_processing` - Fallback for pending payments
- `woocommerce_order_status_completed` - Fallback trigger
- `woocommerce_thankyou` - Final fallback on thank you page

### Order Metadata
- `_bv_pending_post_id` - Links order to listing
- `_bv_listing_paid` - Payment status flag
- `_bv_last_paid_order_id` - Most recent paid order
- `_bv_listing_type` - Listing type for republish validation

## ACF Field Configuration

The plugin expects specific ACF field names:
- `Ilmoituksen_otsikko` - Used as the post title
- `markkinointimateriaali` - File upload field for marketing materials

Field sets are defined per listing type in the plugin. Customize the field arrays in the `bv_edit_listing_shortcode()` function.

## Deployment

The plugin includes a `.cpanel.yml` for automated cPanel deployment:

```yaml
deployment:
  tasks:
    - export DEPLOYPATH=/home/growthrocket/public_html/wp-content/plugins/bv-listing-manager
    - /bin/cp -R * $DEPLOYPATH
```

## File Structure

```
bv-listing-manager/
├── bv-listing-manager.php   # Main plugin file (all functionality)
├── .cpanel.yml              # Deployment configuration
└── README.md                # README file
```

## Hooks & Filters

### Actions
- `admin_post_bv_lm_hide_listing` - Hide a published listing
- `admin_post_bv_lm_republish_listing` - Republish a paid draft
- `wp_ajax_bv_save_listing_draft` - AJAX draft save handler

### Custom Endpoints
- `my-listings` - WooCommerce account endpoint for published listings
- `draft-listings` - WooCommerce account endpoint for draft listings

## Changelog

### v3.2.0
- Live release
- Refactored cart handling to clear removed items
- Suppressed client-side notices on listing pages
- Enhanced WooCommerce Blocks compatibility

## License

Proprietary - All rights reserved.

## Support

For issues and feature requests, contact the development team.
