<?php
/*
Plugin Name: LiteLLM Translator for Loco Translate
Plugin URI: https://github.com/DistintiveLab/wp-loco-litellm-translator
Description: Auto-translate add-on for Loco Translate that sends strings to any OpenAI-compatible LiteLLM endpoint
Author: DistintiveLab
Version: 0.1.0
Requires Plugins: loco-translate
Author URI: https://github.com/DistintiveLab
*/
if( is_admin() ){

    /**
     * Resolve plugin configuration.
     * Defaults may be overridden by constants in wp-config.php, or by the
     * `litellm_translator_config` filter over this whole array.
     * @return string[]
     */
    function litellm_translator_config(){
        $config = array (
            'key' => '',
            'base_url' => 'https://llm.grupo.pro.br/v1',
            'model' => 'dipsik',
            'prompt' => '',
            'temperature' => null,
        );
        if( defined('LITELLM_TRANSLATOR_API_KEY') ){
            $config['key'] = LITELLM_TRANSLATOR_API_KEY;
        }
        if( defined('LITELLM_TRANSLATOR_BASE_URL') ){
            $config['base_url'] = LITELLM_TRANSLATOR_BASE_URL;
        }
        if( defined('LITELLM_TRANSLATOR_MODEL') ){
            $config['model'] = LITELLM_TRANSLATOR_MODEL;
        }
        if( defined('LITELLM_TRANSLATOR_PROMPT') ){
            $config['prompt'] = LITELLM_TRANSLATOR_PROMPT;
        }
        if( defined('LITELLM_TRANSLATOR_TEMPERATURE') ){
            $config['temperature'] = (float) LITELLM_TRANSLATOR_TEMPERATURE;
        }
        return apply_filters('litellm_translator_config',$config);
    }


    // Append our api via the `loco_api_providers` filter hook.
    // This should be available all the time Loco Translate is running.
    function litellm_translator_filter_apis( array $apis ){
        $config = litellm_translator_config();
        // Loco Translate only lists an api when its `key` field is a non-empty string,
        // but anything registered here is exposed to the browser, so only ever use a
        // dummy value. The real key is read server-side at translation time.
        $configured = is_string($config['key']) && '' !== $config['key'];
        $apis[] = array (
            'id' => 'litellm',
            'key' => $configured ? 'configured' : '',
            'name' => 'LiteLLM ('.$config['model'].')',
            'url' => 'https://github.com/DistintiveLab/wp-loco-litellm-translator',
        );
        return $apis;
    }
    add_filter('loco_api_providers','litellm_translator_filter_apis',10,1);


    // Hook our translate function with 'loco_api_translate_{$id}'
    // We only need to do this when the Loco Translate Ajax hook is running.
    function litellm_translator_ajax_init(){
        require __DIR__.'/translator.php';
        add_filter('loco_api_translate_litellm','litellm_translator_process_batch',0,4);
    }
    add_action('loco_api_ajax','litellm_translator_ajax_init',0,0);

}
