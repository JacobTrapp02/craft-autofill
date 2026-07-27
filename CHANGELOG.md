# Release Notes for Autofill
## 1.0.7 - 2026-07-27
- Fixed a bug that cut off plain text fields value
- Fixed change log

## 1.0.6 - 2026-07-07
- Improved the review modal to stay positioned near the active field, flip above fields near the bottom of the viewport, keep the active field highlighted during review, and attempt to open the sidebar/details panel when reviewing sidebar fields.
- Improved the review spotlight so it restores when scrolling the active field off-screen and back into view, and better highlights the field.
- Improved the review modal by removing the separate Reject button, moving Close to the right, and refreshing the entry when closed.

## 1.0.5 - 2026-07-01
- Fixed a bug where closing the Autofill modal wouldn't clear suggestions, because next/prev buttons weren't scoped to specific modals.
- Added a "Force Use Current Values" setting for related entries, merging selected values and suggestions together.

## 1.0.4 - 2026-06-30
- Added a helper method for checking whether a specific Autofill field and entry were already successfully completed.

## 1.0.3 - 2026-06-30
- Added a CP page to run Autofill in bulk, using Craft's queue system and extendable from other plugins.
- Added a CP page showing AI calls.

## 1.0.2 - 2026-06-29
- Fixed Autofill field targeting when a field's label or handle is overridden within an entry type field layout.
- Fixed frontend runtime conflicts so multiple Autofill fields can work independently on the same entry edit page.
- Added entry type compatibility validation so Autofill fields can only be added to the exact entry type they're configured for, with a clear field layout error message.

## 1.0.1 - 2026-06-16
- Changed the Free edition name to Lite throughout the plugin and documentation.

## 1.0.0 - 2026-06-15
- Initial release.