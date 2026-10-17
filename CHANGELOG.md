# Changelog

All notable changes to this custom fork (`asg_orange_hrm`) are documented here.
Format is loosely based on [Keep a Changelog](https://keepachangelog.com/).

## 2026-07-14 06:04 — PIM: External API Select custom field (Type 2)

### Added
- New PIM custom-field type **API Select** (`FIELD_TYPE_API_SELECT = 2`) that fetches
  options from an external HTTP endpoint and renders as an autocomplete in the employee
  profile. The motivating use case is a **Customer** lookup where the field stores a
  `customer_id` (the option's `id`).
- `CustomFieldAutocompleteAPI` — `GET /api/v2/pim/custom-field-options?fieldId=<id>&q=<term>`:
  a server-side proxy so the browser never calls the external API directly.
- `CustomFieldService::getExternalOptions(int $fieldId, string $query): array` — reads the
  field's `extra_data` JSON config, calls the external endpoint via Guzzle 7, and maps the
  response rows to `{id, label}` using the configured `id_field` / `label_field`. Returns
  `[]` on any error or misconfiguration (e.g. `base_url` containing `__SET_ME__`).

### Changed
- `CustomField` entity: `extra_data` column widened from **250 → 1000** characters to hold
  the JSON connection config.
- `ProfileCustomFields.vue`: type 2 fields render as `autocomplete` with a remote loader
  (`makeLoader`); on save the option **id** is persisted, while type 1 (Select) still
  persists the option **label** (legacy behaviour).
- `ProjectAPI.php` (Time): file mode `0755 → 0644` (no functional change).

### Configuration — `extra_data` JSON
```json
{
  "base_url":     "https://customers.example.com",
  "path":         "api/v1/customers",
  "method":       "GET",
  "query":        { "active": "1" },
  "search_param": "q",
  "id_field":     "id",
  "label_field":  "name",
  "timeout":      10,
  "headers":      { "Accept": "application/json" }
}
```
| Key | Default | Description |
| --- | ------- | ----------- |
| `base_url` | *(required)* | External service origin. If it contains `__SET_ME__` the field is treated as unconfigured. |
| `path` | `""` | Appended to `base_url`. |
| `method` | `GET` | HTTP method (upper-cased). |
| `query` | `{}` | Static query params merged with the search term. |
| `search_param` | `q` | Query key receiving the typed search text. |
| `id_field` | `id` | Response row field stored as the value (e.g. `customer_id`). |
| `label_field` | `name` | Response row field shown to the user. |
| `timeout` | `10` | Guzzle request timeout, seconds. |
| `headers` | `{}` | Request headers. |

The response may be a bare JSON array, or an object whose `data` key holds the array.

### Files
- `src/plugins/orangehrmPimPlugin/entity/CustomField.php`
- `src/plugins/orangehrmPimPlugin/Service/CustomFieldService.php`
- `src/plugins/orangehrmPimPlugin/Api/CustomFieldAutocompleteAPI.php` (new)
- `src/client/src/orangehrmPimPlugin/components/ProfileCustomFields.vue`
- `src/plugins/orangehrmTimePlugin/Api/ProjectAPI.php`

> Note: these changes are **uncommitted** as of 2026-07-15.
