# Release Notes for Autofill
## In Progress
- Improvement: review modal now stays positioned near the active field, flips above fields near the bottom of the viewport, keeps the active field highlighted during review, and attempts to open the sidebar/details panel when reviewing sidebar fields
- Improvement: review spotlight now restores when scrolling the active field off-screen and back into view. Make spotlight better light the field

## 1.0.5
- Bug fix: close autofill modal wouldn't clear suggestions: next and prev buttons not specific to modals
- Feature: related entries Force Use Current Values setting - merge selected values and suggestions together

## 1.0.4
- Helper method for whether a specific autofill field and entry were successfully completed already

## 1.0.3
- CP page added to call autofill in bulk. Works with the craft queue system. Extendedable from other plugins as well
- CP page to shows the AI calls

## 1.0.2
- Fixed Autofill field targeting when a field’s label or handle is overridden within an entry type field layout.
- Fixed frontend runtime conflicts so multiple Autofill fields can work independently on the same entry edit page.
- Added entry type compatibility validation so Autofill fields can only be added to the exact entry type they are configured for, with a clear field layout error message.

## 1.0.1
- Renamed the Free edition to Lite throughout the plugin and documentation.

## 1.0.0
- Initial release
