# Magento 2 Admin Action Log

Records what happens in the back office into the
[Activity Log](https://github.com/hryvinskyi/magento2-activity-log) journal: every admin request, and every change to
the store's main entities with the value of each field before and after.

## What it does
- **Admin requests** (`admin` channel)
  - Each admin action is recorded: route, sanitized parameters, response, and the messages the admin saw.
  - Sign-ins, failed sign-ins and sign-outs are recorded too.
  - Page views (GET requests) are left out by default.
  - Routes can be ignored with patterns such as `mui/*`.
- **Entity changes** (`entity` channel)
  - A change is recorded once its transaction commits, with each field's value before and after. A save that is rolled
    back leaves no record.
  - Changes made by admins, integrations, CLI, cron and anonymous callers are recorded by default. Customers and guests
    are left out unless you choose them.
  - Tracked entities:
    - catalog: products, categories, cart and catalog price rules, URL rewrites;
    - customers, orders and order comments;
    - CMS pages and blocks;
    - configuration values;
    - admin users and roles, integrations;
    - store views and websites.
  - Encrypted configuration values are never stored in clear text.
- **Detail panels.** The Activity Log detail page shows an admin request panel and an entity change panel. The change
  panel shows fields grouped by scope.
- **Menu.** The Activity Log menu gets "Admin Requests" and "Entity Changes" entries.

## Requirements
- Magento 2.4 with PHP 8.1 or later
- [`hryvinskyi/magento2-activity-log`](https://github.com/hryvinskyi/magento2-activity-log) ^1.0
- `hryvinskyi/magento2-base` ^2.2
- `hryvinskyi/magento2-configuration-fields` ^1.0.11

## Installation
```bash
composer require hryvinskyi/magento2-admin-action-log
bin/magento module:enable Hryvinskyi_ActivityLog Hryvinskyi_AdminActionLog
bin/magento setup:upgrade
```

## Configuration
**Stores › Configuration › Hryvinskyi › Admin Action Log**

**Admin Requests**
- **Record Admin Requests**: on by default.
- **Record Page Views**: off by default. GET requests only read data; recording them shows who looked at what, at the
  cost of a much larger journal.
- **Ignored Routes**: routes that are never recorded, as `route/controller/action`. `*` matches any text.
- **Keep Admin Requests (days)**: 90 by default.

**Entity Changes**
- **Record Entity Changes**: on by default.
- **Record Changes Made By**: which actors' changes are recorded.
- **Keep Changes (days)**: 90 by default, for every entity without its own row.
- **Per Entity**: per entity type, a mode and a retention.
  - **Changed fields only** stores the fields whose value changed.
  - **Full snapshot** also stores the unchanged ones, so the detail page can show the whole record.

ACL: `Hryvinskyi_AdminActionLog::config` for the settings. Viewing the journal uses the Activity Log's permissions.

## License
MIT. See [LICENSE](LICENSE).

The admin icons are from [Lucide](https://lucide.dev). They are under the ISC licence, and the licence text ships with
them in `view/adminhtml/web/images/icons/`.
