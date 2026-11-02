# LiteLLM Translator for Loco Translate

An add-on for the [Loco Translate WordPress plugin](https://github.com/loco/wp-loco) that
provides an auto-translate API backed by any OpenAI-compatible endpoint, such as a
[LiteLLM](https://docs.litellm.ai/) proxy.

External translation APIs are available in Loco Translate from v2.4.0. In addition to
the [built-in providers](https://localise.biz/wordpress/plugin/manual/providers) you may
define your own service by hooking into the plugin as demonstrated here.

The auto-translation process is invoked from the PO file editor by clicking either the
"Auto" button (for processing the entire file) or by using the "Suggest translation"
button from the editing pane.

## Configuration

The provider appears in Loco Translate only when an API key is configured. Set any of
these constants in `wp-config.php`:

```php
define( 'LITELLM_TRANSLATOR_API_KEY', 'sk-...' );       // required to enable the provider
define( 'LITELLM_TRANSLATOR_BASE_URL', 'https://llm.grupo.pro.br/v1' ); // OpenAI-compatible base URL
define( 'LITELLM_TRANSLATOR_MODEL', 'dipsik' );        // model name known to your endpoint
define( 'LITELLM_TRANSLATOR_PROMPT', '' );              // optional extra prompt instructions
define( 'LITELLM_TRANSLATOR_TEMPERATURE', 0.0 );        // optional decoding temperature
```

Alternatively filter the whole configuration array with the
`litellm_translator_config` WordPress filter.

Strings are sent in batches (roughly 5 KB at a time) as JSON to the
`{base_url}/chat/completions` endpoint, with context and translator notes included, and
the JSON array of translations is mapped back by id. The API key is used server-side
only; it is never exposed to the browser.

This plugin began as a fork of [loco/wp-mock-translator](https://github.com/loco/wp-mock-translator).
