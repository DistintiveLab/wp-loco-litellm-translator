<?php
/**
 * Hook fired as a filter for the "litellm" translation api
 *
 * @param string[] $targets translated strings, initially empty
 * @param string[][] $items input messages with keys, "source", "context" and "notes"
 * @param Loco_Locale $Locale target locale for translations
 * @param array $config This api's configuration
 * @return string[] Translated strings
 */
function litellm_translator_process_batch( array $targets, array $items, Loco_Locale $Locale, array $config ){
    $conf = litellm_translator_config();
    if( '' === $conf['key'] ){
        throw new Loco_error_Exception('LiteLLM Translator: no API key. Define LITELLM_TRANSLATOR_API_KEY in wp-config.php' );
    }

    // GPT wants a wordy language name. We'll handle this with Loco's own data.
    $targetTag = (string) $Locale;
    $targetLang = litellm_translator_language_name($Locale);

    // source language may be posted by the editor, else assume English
    $sourceTag = 'en_US';
    $sourceLang = 'English';
    if( isset($_POST['source']) && is_string($_POST['source']) && '' !== $_POST['source'] ){
        $source = Loco_Locale::parse($_POST['source']);
        if( $source->isValid() ){
            $sourceTag = $_POST['source'];
            $sourceLang = litellm_translator_language_name($source);
        }
    }

    // The front end is already splitting large jobs into batches, but we need smaller ones here.
    // Fill each request with a soft ceiling of ~5KB to keep individual response times down.
    $offset = 0;
    $totalItems = count($items);
    while( $offset < $totalItems ){
        $bytes = 0;
        $batch = [];
        while( $bytes < 5000 && $offset < $totalItems ){
            $item = $items[$offset];
            $meta = array_filter([ 
                isset($item['context']) ? (string) $item['context'] : '',
                isset($item['notes']) ? (string) $item['notes'] : '',
            ]);
            $entry = [
                'id' => $offset,
                'text' => isset($item['source']) ? (string) $item['source'] : '',
                'context' => implode("\n",$meta),
            ];
            $bytes += strlen($entry['text'].$entry['context']);
            $batch[] = $entry;
            $offset++;
        }
        foreach( litellm_translator_request($batch,$sourceLang,$sourceTag,$targetLang,$targetTag,$conf,$Locale) as $id => $text ){
            $targets[$id] = $text;
        }
    }
    return $targets;
}


/**
 * Send one batch of entries to the chat completions endpoint
 * @param array[] $batch entries with "id", "text" and "context" keys
 * @param string[] $conf configuration from litellm_translator_config
 * @param Loco_Locale $Locale target locale for formality handling
 * @return string[] translations mapped by input id
 */
function litellm_translator_request( array $batch, string $sourceLang, string $sourceTag, string $targetLang, string $targetTag, array $conf, Loco_Locale $Locale ){
    // Start prompt with assistant identity and immutable translation instructions
    $instructions = [
        'Respond only in '.$targetLang,
        'Preserve any printf placeholders (e.g. %s, %1$d), HTML tags and HTML entities exactly as given',
        'Return only JSON of the form {"result":[{"id":<same id>,"text":"<translation>"}, ...]} covering every input item, with no additional text',
    ];
    $tone = $Locale->getFormality();
    if( is_string($tone) && '' !== $tone ){
        $instructions[] = 'Use only the '.$tone.' tone of '.$targetLang;
    }
    $prompt = "# Identity\n\nYou are a translator that translates from ".$sourceLang.' ('.$sourceTag.') to '.$targetLang.' ('.$targetTag.").\n\n"
            . "# Instructions\n\n* ".implode(".\n* ",$instructions).'.';
    // Allow user-defined extended instructions
    $custom = trim( (string) $conf['prompt'], "\n* ");
    if( '' !== $custom ){
        $prompt .= "\n\n* ".$custom;
    }

    // Establish temperature; preferring 0.0, but recent GPT models have minimum 1.0
    $temperature = 0.0;
    if( isset($conf['temperature']) && is_numeric($conf['temperature']) ){
        $temperature = (float) $conf['temperature'];
    }
    else if( preg_match('!^(?:openai/)?gpt-(\d+)!',$conf['model'],$vers) && intval($vers[1]) > 4 ){
        $temperature = 1.0;
    }

    // https://platform.openai.com/docs/api-reference/chat/create
    $payload = [
        'model' => $conf['model'],
        'temperature' => $temperature,
        'messages' => [
            [ 'role' => 'system', 'content' => $prompt ],
            [ 'role' => 'user', 'content' => 'Translate the `text` properties of the following JSON objects, using the `context` property to identify the meaning.' ],
            [ 'role' => 'user', 'content' => json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ],
        ],
    ];

    $endpoint = rtrim($conf['base_url'],'/').'/chat/completions';
    $response = wp_remote_post( $endpoint, [
        'method' => 'POST',
        'timeout' => 120,
        'redirection' => 0,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$conf['key'],
        ],
        'body' => json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ] );

    if( is_wp_error($response) ){
        $name = isset($conf['name']) ? $conf['name'] : 'LiteLLM';
        throw new Loco_error_Exception( $name.': '.$response->get_error_message() );
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode( wp_remote_retrieve_body($response), true );
    if( 200 !== $status ){
        $message = is_array($data) && isset($data['error']['message']) ? $data['error']['message'] : null;
        if( is_null($message) && is_array($data) ){
            // some vendors return an array of error objects
            foreach( $data as $item ){
                if( isset($item['error']['message']) ){
                    $message = $item['error']['message'];
                    break;
                }
            }
        }
        if( is_null($message) ){
            $message = 'Unknown error';
        }
        throw new Loco_error_Exception( sprintf('API returned status %u: %s',$status,$message) );
    }

    $content = null;
    if( is_array($data) && isset($data['choices'][0]['message']['content']) ){
        $content = $data['choices'][0]['message']['content'];
    }
    $parsed = litellm_translator_parse_content($content);
    if( is_null($parsed) ){
        throw new Loco_error_Exception('API returned unexpected data: '.var_export($content,true) );
    }

    $targets = [];
    foreach( $batch as $entry ){
        $targets[$entry['id']] = '';
    }
    foreach( (array) $parsed['result'] as $output ){
        if( isset($output['id'], $output['text']) && is_string($output['text']) ){
            $id = (int) $output['id'];
            if( array_key_exists($id,$targets) ){
                $targets[$id] = $output['text'];
            }
        }
    }
    return $targets;
}


/**
 * Extract a translations object from a chat response that should be, but may not be, pure JSON
 * @param string|null $content
 * @return array|null decoded `{"result":[...]}` structure
 */
function litellm_translator_parse_content( $content ){
    if( ! is_string($content) || '' === $content ){
        return null;
    }
    $content = trim($content);
    // strip markdown code fences if present
    if( preg_match('!^```[a-z]*\s*(.+?)\s*```$!s',$content,$match) ){
        $content = $match[1];
    }
    $data = json_decode($content,true);
    // some models wrap prose around the JSON; grab the outermost object
    if( ! is_array($data) && preg_match('/\{.*\}/s',$content,$match) ){
        $data = json_decode($match[0],true);
    }
    if( is_array($data) && array_key_exists('result',$data) && is_array($data['result']) ){
        return $data;
    }
    return null;
}


/**
 * @param Loco_Locale $Locale
 * @return string English language name, e.g. "Portuguese"
 */
function litellm_translator_language_name( Loco_Locale $Locale ){
    $names = Loco_data_CompiledData::get('languages');
    $lang = $Locale->lang;
    return isset($names[$lang]) ? $names[$lang] : $lang;
}
