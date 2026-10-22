# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-22

### Added
- Search keys for entity changes on the Activity Log search page: `entity`, `product`, `category`, `page`, `block`,
  `customer`, `order`, `rule`, `config`, `field`, `store` (the scope of a change), `value` and `text`.
  - A record key takes `#id` for exactly one record; for an order, `#<n>` is also the exact increment id. Any other
    value matches the record's natural key typed in full (SKU, email, identifier, increment id) or part of its name.
    When no existing record matches, the names the journal recorded are used, so deleted and renamed records are
    found.
  - `config` matches whole path segments.
  - `field: "price"`, in quotes, matches that field code exactly. Without quotes, the value matches part of a field
    code or a whole label.
  - Masked values are never matched.
  - Suggestions for these keys, and four example searches for an empty log.
  - The `entity` channel is registered as one that records changes, so "Changes only" is offered.
- Search results describe entity changes: the verb, the fields named as the admin sees them, values shown as options
  name them, and a link to the record's edit page.
- An Activity button on the edit pages of products, categories, CMS pages, CMS blocks and customers. It shows the
  number of changes of the last 30 days and opens a slide-out panel with the filters Field, Actor and Period, and
  "All store views" for a record edited per store view.
- On the product page, a hint under each field changed in the last 7 days, with a History link that opens the panel
  on that field.
- A history icon after each setting on Stores › Configuration that stores a value. An orange dot means a change in the
  last 7 days. The icon opens a popover with the setting's changes in all scopes, Copy and "Open in Activity Log".
- No admin markup is added, and no query runs, for an admin without `Hryvinskyi_ActivityLog::view`.
- An index on the change table's fields `(field, is_changed, activity_id)`. `setup:upgrade` builds it; on a large log
  that takes a moment.
- A tracked entity can be named by several fields (`labelFields` of `Model\EntityChange\TrackedEntity`).
- A product's stock item is recorded as a change of the product, under its id and name, when it is saved through the
  catalog inventory services (the product form, `PUT /V1/products/{sku}/stockItems/{itemId}`, the stock registry and the
  stock item repository): Quantity, Stock Status and the Advanced Inventory settings, each with its "Use Config
  Settings". They are shown under the form's labels, quantities without padding zeros.
- `Api\TrackedEntityPartInterface` and the tracked entity pool's `parts` argument: a model that keeps part of an
  entity's data is recorded as that entity. A module that records the same values from elsewhere replaces the
  `product_stock_item` part. `Model\EntityChange\TrackedEntity` takes `readsStoredState`, to compare with the stored row
  rather than the original data a request kept.
- `Api\EntityChangeRecorderInterface` with `Api\Data\EntityChangeInterface`: records a change of a tracked entity that
  was written without its model, under the same rules as the changes of saved models (switches, mode, retention,
  actors, masking, comparison, no record without a changed field). The record is written at once, inside the
  caller's transaction when one is open, and recording never fails the caller. Changes of saved models and recorded
  changes are written by the same code.
- `Model\Data\EntityScope` can be created without arguments (the global scope) and copied with `withValues()`.
- The default **Ignored Routes** list holds the routes of the search page: `hryvinskyi_activitylog/activity/search`,
  `…/suggest` and `…/savePreference`.
- Changes Magento makes on its own while an admin saves a form are marked "set automatically": a changed field the
  form submitted no value for (an attribute of the record), submitted as it was, or kept on its "Use Default Value" or
  "Use Config Settings" box. Examples: image labels the media gallery clears, a Yes/No setting stored as "No" where
  it followed the configuration. The mark is stored with the field (`is_automatic` on the change field table,
  set by `setup:upgrade`); rows recorded before stay unmarked. Only the record the form saves is judged; changes made
  outside an admin action are never marked. The forms are described per tracked entity in `di.xml` (products,
  categories, customers, CMS pages and blocks).
- Search results, the record panel and the detail page tag such fields; the sentence counts only the fields asked for,
  and a change made only of them reads "Magento updated …". The grid's change columns show the fields asked for. The
  product field hints leave them out.
- `Api\EntityChangeRecorderInterface::recordAll()` records the changes of one write together: the records they name
  are read in one query, from their naming fields only (SKU and name for a product), and each change is recorded on
  its own.
- `Api\SecretFieldsInterface` and the tracked entity pool's `secretFields` argument: fields secret by an entity's
  nature, told from the field alone. Changes of saved models and recorded changes are masked by the same rule; a
  setting the configuration keeps encrypted is masked whatever its path is called.
- `Api\TrackedEntityPartInterface::getModelClass()` and `stateAfter()`. The pool holds at most one part per entity and
  model class and fails when a second one is added beside it.
- The `store` key also matches any name a change recorded for its scope, typed whole ("Default Source").
- `Model\Search\Field\StaticFields`, `Model\Search\Field\Value\ValueNamesInterface`, `…\Value\Quantities` and
  `…\Value\OptionSourceNames` are `@api`; the README's *Extending* section lists the stable item and virtual type names.
- Requirements: `hryvinskyi/magento2-activity-log` ^1.1, `Magento_Ui` and `Magento_CatalogInventory`.

### Changed
- Values and field names are shown as the admin sees them: Yes and No and other option labels, "Group › Field" for a
  setting, and attribute labels. A decimal keeps at least two places.
- A change is named when it is recorded as "Product <SKU> · <name>" or "Category <name> (#id)". A sentence leaves out
  the repeated kind of record and the field list at the end of a recorded name, for old and new rows alike.
- A store that saved its own **Ignored Routes** keeps its list. Add the three search routes to it by hand.

### Fixed
- Admin edits are no longer marked "set automatically" when the product form sends the field twice or not at all:
  - "Use Default Value" ticked at a store view over a value of "1" (Enabled) is asked for: the field's own old value
    no longer reads as a ticked box;
  - the Quantity and Stock Status typed into the product form are read before the values the form loaded and sends
    back under `stock_data`;
  - removing every category, or emptying another list, is asked for, although the form then sends no list.
- A stock item's change is compared with its stored row after it commits, so a save rolled back by an outer
  transaction records nothing when its commit callback fires later.
- A part of an entity that reports no state before its change records an update of the entity, never its creation.
- A Yes/No field (`frontend_input` boolean) that held no value and is saved as No is no longer recorded as changed:
  no value and "0" are the same for it. This applies to saves from 1.1.0 on; changes recorded before keep their rows.
- Saving a form without touching a text field no longer records it as changed when only its line endings differ
  ("\n" stored, "\r\n" submitted). The rule holds for every field.
- A product's stock setting with "Use Config Settings" ticked before and after a save is no longer recorded as changed
  when the save writes the configuration's value into its column (0 → 10000 for Maximum Qty Allowed in Shopping Cart
  on the first admin save of an imported product). `FieldEquivalences` takes the boxes as `followedSettings`.
- `field: Attribute Set` (and the other labelled product fields kept by id) finds the field by its label.
- The record panel's count "N of M" counts M over all the record's changes, whatever field, actor, scope or period is
  chosen.
- A setting cleared to an empty value shows "(empty)" in the configuration history popover.
- A product's categories are shown by their path and id ("Gear › Bags & Luggage #42"), so categories of the same name
  stay apart, with a deleted category as `#42`, and the items added and removed are told by name. The attribute set and the product type are shown by name, under the labels
  "Attribute Set" and "Product Type". Search still matches the stored ids.
- Date attributes are shown as a date in the admin's locale ("Oct 5, 2026"); date-and-time attributes with their time.
  What is stored and searched is unchanged.
- A change recorded in "Changed fields only" mode no longer offers to open unchanged fields that were never stored, and
  no longer calls them a snapshot: it gives their number only.
- Categories no longer record `entity_id` as a field when they are created.
- A product created through the product repository is recorded as created, not saved: a model without an id is new,
  whatever original data its own save filled in (a product notes its websites while it saves).
- **Ignored Routes** patterns match without regard to letter case.
- The "Admin Requests" and "Entity Changes" menu entries are built from the route id, so they work with **Add Secret
  Key to URLs** on.

## [1.0.0] - 2026-10-21

### Added
- Admin requests (`admin` channel): route, sanitized parameters, response and the messages the admin saw, for each admin
  action; sign-ins, failed sign-ins and sign-outs. Page views are left out by default, and routes can be ignored with
  patterns.
- Entity changes (`entity` channel), recorded when the transaction commits, with each field's value before and after,
  for products, categories, price rules, URL rewrites, customers, orders and their comments, CMS pages and blocks,
  configuration values, admin users and roles, integrations, store views and websites.
- An admin request panel and an entity change panel on the Activity Log detail page, and the menu entries "Admin
  Requests" and "Entity Changes".
- Settings for both channels, with a retention and a mode (changed fields only, or full snapshot) for each entity.
