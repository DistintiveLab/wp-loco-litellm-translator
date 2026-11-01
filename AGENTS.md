# AGENTS.md

Guidance for AI agents working in this repository.

## What this repository is

A WordPress plugin that adds a custom auto-translation API provider to the
[Loco Translate](https://github.com/loco/wp-loco) plugin (requires Loco
Translate >= 2.4.0; current code targets the 2.7+ provider API). The provider
id is `litellm`; it sends strings to any OpenAI-compatible chat completions
endpoint (by default a LiteLLM proxy at `https://llm.grupo.pro.br/v1` with
model `ipeagpt`) and maps JSON translations back by id.

The plugin began as a fork of [`loco/wp-mock-translator`](https://github.com/loco/wp-mock-translator)
v1.1.0; its structure (two procedural files, hook wiring, code style) is
inherited from that upstream.

## Repository layout

Only two source files; there is no build system, no Composer, no tests, and
no CI.

- `plugin.php` — WordPress plugin entry point (plugin header, provider
  registration, config resolution, hook wiring).
- `translator.php` — the translation implementation, loaded lazily only when
  Loco Translate's Ajax endpoint runs.
- `README.md` — user-facing configuration documentation.

## Essential commands

There is nothing to build or run standalone. **No PHP runtime is available
on this machine** (`php` is not installed). Syntax checking is done with the
Python `phply` parser instead:

```sh
python3 - <<'EOF'
from phply.phplex import lexer
from phply.phpparse import make_parser
for f in ['plugin.php','translator.php']:
    make_parser().parse(open(f, encoding='utf-8').read(), lexer=lexer.clone(), tracking=True)
    print(f, 'PARSE OK')
EOF
```

Caveat: the distro phply grammar is PHP 5.x-era, so `??` (null coalescing)
and other PHP 7+ syntax fail to parse even though they are valid. Write
`isset($x) ? $x : $y` instead of `$x ?? $y` in this codebase (it is also
more portable). Functional testing requires a WordPress install with Loco
Translate active, invoking auto-translation from the PO editor ("Auto"
button or "Suggest translation" in the editing pane).

## Configuration model

All settings resolve through `litellm_translator_config()` in `plugin.php`:
defaults -> `LITELLM_TRANSLATOR_*` constants (wp-config.php) -> the
`litellm_translator_config` WordPress filter. Keys: `key`, `base_url`,
`model`, `prompt`, `temperature`.

## Loco Translate integration contract (do not break)

Verified against wp-loco master (`Loco_ajax_ApisController::render()`,
`Loco_api_Providers`):

1. **`loco_api_providers` filter**: append an array with `id`, `name`, `url`,
   `key`. Loco only *lists* a provider whose `key` is a non-empty string.
   **The registration array is exposed to the browser** (the modal renders
   from `redacted()`, which only redacts built-in providers, not ones added
   via the filter), so never put a real secret in `key`; use a dummy like
   `'configured'` when usable and `''` to hide the provider.

2. **`loco_api_ajax` action** (priority 0): fired by Loco's Ajax route only
   when a translation is actually requested. It is used to lazy-`require`
   `translator.php` and register the translate filter. Keep heavy code out
   of the normal admin page load.

3. **`loco_api_translate_litellm` filter** (priority 0, **4 arguments**):
   called as `apply_filters($action, [], $sources, $locale, $config)`.
   - `$targets` arrives empty; return one entry per input index (the
     controller warns if `count($targets) !== count($sources)`).
   - Each `$sources` item has keys `source`, `context`, `notes` (context/
     notes may be empty or absent; guard with `isset`).
   - `$config` is the provider registration array plus `type` (text/html)
     posted by the front end. Our implementation ignores it and re-reads
     config via `litellm_translator_config()`.
   - Throw `Loco_error_Exception` on failure; Loco's Ajax router converts
     it into a user-facing error. Loco classes (`Loco_Locale`,
     `Loco_data_CompiledData`) are guaranteed loaded in this context.
   - Source language may be posted as `$_POST['source']` (locale tag);
     default assumption is `en_US`.

## Code style and conventions

- Procedural PHP only: no classes, no namespaces, no autoloading. Functions
   live in the global namespace with the `litellm_translator_` prefix.
- Formatting quirk inherited from upstream: space *inside* parentheses in
   control structures, e.g. `if( is_admin() ){`, `foreach( $items as $i => $item ){`,
   and often no space after commas (`substr($source,$length)`). Match it.
- Everything in `plugin.php` is wrapped in `if( is_admin() )` — the plugin
  does nothing on front-end requests.
- The plugin header includes `Requires Plugins: loco-translate`, so
  WordPress enforces the dependency.
- HTTP goes through the WordPress HTTP API (`wp_remote_post`,
  `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`,
  `is_wp_error`), never cURL directly.

## Gotchas

- The design mirrors Loco's built-in `Loco_api_ChatGpt` provider (batching
  ~5 KB of source text per request, wordy language names from
  `Loco_data_CompiledData::get('languages')`, formality via
  `$Locale->getFormality()`, temperature 0.0 bumped to 1.0 for `gpt-5`+
  model names). If changing translation behavior, re-check that upstream
  for reference.
- The response parser (`litellm_translator_parse_content`) is deliberately
  defensive: it strips markdown code fences and extracts the outermost
  `{...}` because not every model behind a LiteLLM proxy honours
  structured-output parameters. Don't assume the content is clean JSON.
- Translation ids are positional offsets into the original items array;
  they must survive the round-trip so `$targets[$id]` lines up with the
  inputs. Items the model skips stay as `''` (Loco treats them as
  untranslated).
- Changing the provider `id` requires changing the filter name
  `loco_api_translate_{id}` on the other side to match.
