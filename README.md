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
    - catalog: products (with their stock: see below), categories, cart and catalog price rules, URL rewrites;
    - customers, orders and order comments;
    - CMS pages and blocks;
    - configuration values;
    - admin users and roles, integrations;
    - store views and websites.
  - Encrypted configuration values are never stored in clear text.
- **Detail panels.** The Activity Log detail page shows an admin request panel and an entity change panel. The change
  panel shows fields grouped by scope.
- **Menu.** The Activity Log menu gets "Admin Requests" and "Entity Changes" entries. They open the search page on the
  `admin` and `entity` channels.
- **Search keys.** Entity changes answer keys on the Activity Log search page (see below).
- **Activity button.** The edit pages of products, categories, CMS pages, CMS blocks and customers get an Activity
  button (see below).
- **Settings history.** Each setting on Stores › Configuration gets a history icon (see below).

### Search keys
The search page takes these keys for entity changes. This module registers the `entity` channel as one that records
changes, so "Changes only" is offered on the search page and is on by default.

| Key | Matches |
|-----|---------|
| `entity` | One kind of entity, by its code or label, such as `cms_block` or "CMS block" |
| `product` | A product: SKU, ID or name |
| `category` | A category: ID or name |
| `page` | A CMS page: identifier or title |
| `block` | A CMS block: identifier or title |
| `customer` | A customer: email, ID or name |
| `order` | An order: increment id, with its comments; `#<n>` is the order with that id or that exact increment id |
| `rule` | A cart or catalog price rule: name |
| `config` | A setting by its config path, or every setting under a group's path |
| `field` | The changes of one field |
| `store` | The scope of a change: a store view or website by code or whole name, or "Default Config", "All Store Views", "Global" (kept apart, though both are the default scope), or any other name a change recorded for its scope, typed whole ("Default Source"). Case does not matter. Part of a name matches only when no store view or website has that code or name; several scopes separated by `\|` match any of them. The journal answers the same key with the store view a request ran in, and the results are joined. |
| `value` | Text in a changed field's value before or after the change |
| `text` | Words anywhere in a change: each word in a changed field's code, its label (words of 3 characters or more), or a value before or after |

- A record key takes `#id` to name one record by its id and nothing else: `order: #12` never finds order 120. Any other
  value matches the record's natural key typed in full (SKU, email, identifier, increment id), or part of its name. When no existing record
  matches, the key falls back to the names the journal recorded, so the history of a deleted or renamed record can still
  be found.
- `config: catalog/frontend` matches whole path segments: `a/b` finds every setting of the group `a/b` and never `a/bc`.
- `field: "price"`, in quotes, matches that field code exactly and never `special_price`. Without quotes the value
  matches part of a field code, or a whole field label.
- `field` finds the changes in which the field changed. A field that a full snapshot kept unchanged does not count.
- `value` and `text` have no index, so they are matched among the newest 10,000 candidates the other filters leave.
- Masked values are never matched. A masked field is found by its name only.

### Values, names and labels
- Values and field names are shown as the admin sees them: option labels such as Yes and No, "Group › Field" for a
  setting, and attribute labels. A decimal keeps at least two places ("34.000000" shows as "34.00").
- A product's attribute set and type are shown by name, its categories by their path and id ("Gear › Bags & Luggage
  #42", so two categories of the same name stay apart), or `#42` alone for a category deleted since. Dates are shown in the admin's locale ("Oct 5, 2026"), with the time only for a
  date-and-time attribute. Only the display changes: search matches the stored ids and dates.
- A Yes/No field that held no value and is saved as No is not recorded as a change: both read as No. A text saved back
  with other line endings (a browser sends a text area's line breaks as "\r\n") is not recorded as a change either.
- A stock setting whose "Use Config Settings" box is ticked before and after a save is not recorded as a change, even
  when the save writes the configuration's value into the stock item's own column (as the first admin save of an
  imported product does: "Maximum Qty Allowed in Shopping Cart 0 → 10000"). The shop followed the configuration both
  times. Ticking or clearing the box is recorded, with the setting's values.
- Fields that kept their value are listed only when a full snapshot stored them; otherwise only their number is
  shown.
- A change is named when it is recorded: "Product <SKU> · <name>" and "Category <name> (#id)". A sentence leaves out the
  repeated kind of record and the field list at the end of the recorded name. Rows recorded before 1.1.0 are shown the
  same way; the journal keeps what it recorded.

### Stock
- A product's stock item is recorded as a change of the product: under the product's id and name, with the product's
  mode and retention. The fields are those the product form's quantity and Advanced Inventory edit: Quantity, Stock
  Status (In Stock / Out of Stock), Manage Stock, Backorders, Out-of-Stock Threshold, the minimum and maximum quantity
  allowed in the cart, Notify for Quantity Below, Enable Qty Increments, Qty Increments, Qty Uses Decimals and Allow
  Multiple Boxes for Shipping, each with its "Use Config Settings". What Magento derives from them (the low stock date,
  whether the status changed by itself) is left out. Quantities are shown without padding zeros ("100", "4.5").
- It is recorded when the stock item is saved through Magento's catalog inventory services: the product form, the REST
  endpoint `PUT /V1/products/{sku}/stockItems/{itemId}`, `StockRegistryInterface::updateStockItemBySku()` and
  `StockItemRepositoryInterface::save()`. A stock item saved for the first time (a new product's) is a change of the
  product, not a second creation. Deleting a stock item on its own records nothing: the product's deletion is recorded.
- Another module that records the same values from another source of stock replaces the `product_stock_item` item of
  the tracked entity pool's `parts` argument with a part that leaves those values out
  (`Api\TrackedEntityPartInterface`, for example a decorator of `Model\EntityChange\ProductStockItemPart` that narrows
  `fields()`). That module owns the rule, so a change is never recorded twice. An entity has at most one part per
  model class: a part added beside the stock item's, instead of replacing it, makes the pool fail when it is built.
- A stock item's state after its change is read from its row once the change committed, not from the model. A save
  rolled back by an outer transaction leaves the model holding values that were never written, and its commit callback
  can still fire on a later commit; compared with the row, it records nothing.

### Recording changes made without a model
`Api\EntityChangeRecorderInterface::record()` records a change of a tracked entity that a service wrote without
saving the entity's model (stock kept in a store of its own, for example). `recordAll()` records the changes of one
write together: the records they name are read in one query, and each change is recorded on its own, so one that
fails is logged and never keeps the others from being recorded. Build the change with
`Api\Data\EntityChangeInterfaceFactory::create()` and its `with…()` methods: the entity's code and id, the operation
(an update unless said otherwise), the scope (type, id and a name to show) and each field's value before and after,
as the text a change stores.
- The rules of the changes of saved models apply: nothing is recorded while entity changes or the journal are off,
  for an entity whose mode is Off, for an actor not chosen, or for an entity code nobody tracks. Values that mean the
  same are no change, and a change without a changed field is not recorded. The entity's mode and retention apply.
- Secret fields are masked by the same rule as the changes of saved models, told from the field alone: a name that
  reads as a secret, or a field the entity's `secretFields` item of the tracked entity pool calls secret. A setting the
  configuration keeps encrypted is masked whatever its path is called.
- Products and categories are named as their own changes name them ("Product 24-MB01 · Joust Duffle Bag"), from their
  SKU and name only, read for all the changes of one call at once; any other entity, or a record that is gone, by its
  label and id ("CMS block #44").
- The record is written at once through the default connection. Inside an open transaction it is part of that
  transaction: a change that is rolled back leaves no record.
- Recording never fails the caller. A failure is logged.

To show the fields such a module records as the admin sees them, add them to the product fields kept outside the
attributes (the `fields` argument of the `Model\Search\Field\ProductStaticFields` virtual type) with a label and the
names of their values; they are then also found by their labels.

### Changes Magento makes on its own
Saving an admin form can change fields the admin never touched: Magento's media gallery clears a stored image label
when the gallery's own label is empty, and a setting that followed the configuration is stored as an explicit "No".
These changes stay in the log and are marked **set automatically**.
- A changed field is set automatically when it changed while an admin saved the record's form, and the form did not
  ask for it:
  - the form submitted no value for it, it is an attribute of the record (products, categories, customers), and it
    holds no list. A field the record does not keep itself, such as a quantity per source, may be submitted under
    another name, so its absence says nothing; nor does the absence of a list (a value held as a JSON list, such as
    the categories, or a multiple-choice attribute), which a form leaves out once it is emptied;
  - the form submitted the value the field had before, compared as changes are compared. Where a form sends a field
    twice, the name listed first decides: the product form sends the Quantity and Stock Status the admin edits under
    `quantity_and_stock_status` and the values it loaded under `stock_data`;
  - its "Use Default Value" or "Use Config Settings" box was submitted ticked and stayed ticked: the field held no
    value of its own before, or the box is a stored field of its own (a stock setting's "Use Config Settings") that
    was ticked before. A box named by the field's own code ("Use Default Value") keeps no state apart from the field,
    so the field's old value never reads as a ticked box. A box the admin just ticked asks for the change;
  - it has a "Use Config Settings" box, held no value, and the form sent it blank ("" or "0"): the form shows such a
    field off with the box clear, so it sent back what it showed.
- Only the record the form saves is judged: the product, category, customer, CMS page or block whose id the form
  submits. A new record, another record changed in the same request, and every change made outside an admin action
  (the web API, a console command, cron) are never marked. When in doubt, a change is not marked.
- Where each form puts its values is configured per tracked entity in `di.xml` (the `forms` argument of
  `Model\EntityChange\Automatic\AutomaticFields`); another module describes its own form there.
- The search page and the record panel tag such a field "set automatically" and count only the fields the admin asked
  for in the sentence. A change made only of them reads "Magento updated …", with the admin named below. The grid's
  Field and Before → After columns show the fields asked for. The product field hints leave them out, and the detail
  page tags them. Search finds them like any other change.
- Changes recorded before 1.1.0 are never marked.

### The Activity button
- A button on the edit pages of products, categories, CMS pages, CMS blocks and customers shows the number of changes
  of the record in the last 30 days.
- It opens a slide-out panel with the record's changes. The panel has the filters Field, Actor and Period (Last 7 days,
  Last 30 days or All time). For a record that is edited per store view, it also has "All store views". "Open in
  Activity Log" opens the search page on the same filters.
- On a product, each field that changed in the last 7 days gets a hint under it: when, by whom and from what to what,
  with a History link that opens the panel on that field. A new product gets no hints.

### Settings history
- A clock icon follows each setting on Stores › Configuration that stores a value. An orange dot means that the setting
  changed in the last 7 days.
- The icon opens a popover with the history of the setting in all scopes: when, who (with the client and channel), the
  scope, and the value before and after. It has Copy for the config path and "Open in Activity Log".
- A setting that never changed since logging started says so.

An admin without `Hryvinskyi_ActivityLog::view` sees none of this: no button, no panel, no icon, and no queries run.

## Extending
These are the stable extension points other modules build on. Their names and contracts are kept within 1.x.
- **Tracked entities and their parts.** The tracked entity pool (`Model\EntityChange\TrackedEntityPool`) takes
  `entities` (`Api\TrackedEntityInterface`), `parts` (`Api\TrackedEntityPartInterface`) and `secretFields`
  (`Api\SecretFieldsInterface`), each keyed by item name or entity code. The `parts` item `product_stock_item` and the
  virtual type `Model\EntityChange\ProductStockItemPart` it holds are stable: a module recording a product's stock
  from another source replaces that item, usually with a decorator of that virtual type. One part per entity and
  model class; a second one fails the pool.
- **Recording without a model.** `Api\EntityChangeRecorderInterface` with `Api\Data\EntityChangeInterfaceFactory`
  (see above).
- **Fields kept outside the attributes.** The virtual type `Model\Search\Field\ProductStaticFields` (a
  `Model\Search\Field\StaticFields`) takes a `fields` item per field code, with a `label` and the `names` of its
  values (`Model\Search\Field\Value\ValueNamesInterface`). Ready ones: `Model\Search\Field\Value\Quantities` (a
  decimal without padding zeros) and the virtual type `Model\Search\Field\Value\StockStatusNames` (In Stock / Out of
  Stock, a `Model\Search\Field\Value\OptionSourceNames`).
- **Forms.** The `forms` argument of `Model\EntityChange\Automatic\AutomaticFields` describes where each tracked
  entity's admin form puts what it submits (see *Changes Magento makes on its own*).

## Requirements
- Magento 2.4 with PHP 8.1 or later
- [`hryvinskyi/magento2-activity-log`](https://github.com/hryvinskyi/magento2-activity-log) ^1.1
- `hryvinskyi/magento2-base` ^2.2
- `hryvinskyi/magento2-configuration-fields` ^1.0.11

## Installation
```bash
composer require hryvinskyi/magento2-admin-action-log
bin/magento module:enable Hryvinskyi_ActivityLog Hryvinskyi_AdminActionLog
bin/magento setup:upgrade
```

`setup:upgrade` adds an index on the change table's fields `(field, is_changed, activity_id)`. On a large log that
takes a moment.

## Configuration
**Stores › Configuration › Hryvinskyi › Admin Action Log**

**Admin Requests**
- **Record Admin Requests**: on by default.
- **Record Page Views**: off by default. GET requests only read data; recording them shows who looked at what, at the
  cost of a much larger journal.
- **Ignored Routes**: routes that are never recorded, as `route/controller/action`. `*` matches any text, and case does
  not matter. The default list holds the routes of the Activity Log search page (`hryvinskyi_activitylog/activity/search`,
  `…/suggest` and `…/savePreference`), so that searching is not itself recorded. A store that saved its own list keeps
  it: add those three routes to it by hand.
- **Keep Admin Requests (days)**: 90 by default.

**Entity Changes**
- **Record Entity Changes**: on by default.
- **Record Changes Made By**: which actors' changes are recorded.
- **Keep Changes (days)**: 90 by default, for every entity without its own row.
- **Per Entity**: per entity type, a mode and a retention.
  - **Changed fields only** stores the fields whose value changed.
  - **Full snapshot** also stores the unchanged ones, so the detail page can show the whole record.

ACL: `Hryvinskyi_AdminActionLog::config` for the settings. Viewing the journal uses the Activity Log's permissions.

## Known limits
- A denied write has no "tried to change" or "tried to delete" sentence yet. It is shown with its Denied outcome.
- Order comments and settings have no link to an edit page.
- The first save of a configuration section records every field it writes.
- A change Magento makes on its own is marked only when the form's data shows it was not asked for. A field the form
  sends under another name than the one recorded, or not at all although the record keeps it elsewhere, is never
  marked, and neither is an emptied list.
- A record named for changes recorded without a model is read once per request: a product renamed earlier in the same
  request keeps the name it had when it was first read.
- Stock written with plain SQL is not recorded: the quantity an order takes, an import, the update of every item after
  a stock setting changes, and a per-source quantity copied into the stock item by a module that keeps stock per
  source.
- A new product's stock is recorded in the same request as the product's creation, but just before it.

## License
MIT. See [LICENSE](LICENSE).

The admin icons are from [Lucide](https://lucide.dev). They are under the ISC licence, and the licence text ships with
them in `view/adminhtml/web/images/icons/`.
