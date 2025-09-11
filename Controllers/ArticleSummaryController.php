<?php

class FreshExtension_ArticleSummary_Controller extends Minz_ActionController
{
  public function summarizeAction()
  {
    $this->view->_layout(false);
    header('Content-Type: application/json');

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt_base = FreshRSS_Context::$user_conf->oai_prompt;
    $oai_prompt_more = FreshRSS_Context::$user_conf->oai_prompt_2;
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider;
    $use_more = Minz_Request::param('more');
    $oai_prompt = $use_more ? $oai_prompt_more : $oai_prompt_base;

    if (
      $this->isEmpty($oai_url)
      || $this->isEmpty($oai_key)
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      echo json_encode([
        'response' => ['data' => 'missing config', 'error' => 'configuration'],
        'status' => 200
      ]);
      return;
    }

    $entry_id = Minz_Request::param('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);
    if ($entry === null) {
      echo json_encode(['status' => 404]);
      return;
    }

    $content = $entry->content();
    // ⚠️ Ajout : on construit $markdownContent pour l’option Ollama (et c’est souvent mieux pour GPT aussi)
    $markdownContent = $this->htmlToMarkdown($content);

    // Normalise l’URL pour compat Ollama/OpenAI
    $oai_url = rtrim($oai_url, '/');
    if ($oai_provider !== 'ollama' && !preg_match('/\/v\d+\/?$/', $oai_url)) {
      $oai_url .= '/v1';
    }

    // Réponse par défaut : OpenAI via PROXY même-origine
    $successResponse = [
      'response' => [
        'data' => [
          'oai_url' => Minz_Url::display('/p/ext.php?f=xExtension-ArticleSummary/proxy_responses'),
          // 👇 body attendu par /v1/responses, que le proxy forwardera tel quel
          'payload' => [
            'model' => $oai_model,
            'input' => [
              [ 'role' => 'system', 'content' => $oai_prompt ],
              [ 'role' => 'user',   'content' => "input:\n" . $markdownContent ],
            ],
            'reasoning' => [ 'effort' => 'minimal' ],
            'max_output_tokens' => 2048,
            'temperature' => 1,
            'stream' => true,
          ],
          // Pour éviter "Bearer undefined" côté JS, on renvoie une chaîne vide (le proxy ignore ce header)
          'oai_key' => ''
        ],
        'provider' => 'openai',
        'error' => null
      ],
      'status' => 200
    ];

    // Branche spéciale OLLAMA (si tu appelles un Ollama même-origine, tu peux laisser direct.
    // Sinon, il faudra aussi passer par un proxy local si CSP bloque)
    if ($oai_provider === 'ollama') {
      $successResponse = [
        'response' => [
          'data' => [
            'oai_url' => rtrim($oai_url, '/') . '/api/generate',
            'oai_key' => '', // pas nécessaire, garde vide
            'model' => $oai_model,
            'system' => $oai_prompt,
            'prompt' => $markdownContent,
            'stream' => true,
          ],
          'provider' => 'ollama',
          'error' => null
        ],
        'status' => 200
      ];
    }

    echo json_encode($successResponse);
    return;
  }

  public function speakAction()
  {
    $this->view->_layout(false);
    header('Content-Type: application/json');

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $tts_model = FreshRSS_Context::$user_conf->oai_tts_model;
    $voice = FreshRSS_Context::$user_conf->oai_voice;
    $speed = FreshRSS_Context::$user_conf->oai_speed;
    if ($speed === null || !is_numeric($speed)) { $speed = 1.1; }
    $speed = max(0.5, min(4, (float)$speed));

    $content = Minz_Request::param('content');

    if (
      $this->isEmpty($oai_url) ||
      $this->isEmpty($oai_key) ||
      $this->isEmpty($tts_model) ||
      $this->isEmpty($voice) ||
      $this->isEmpty($content)
    ) {
      echo json_encode([
        'response' => ['data' => 'missing config', 'error' => 'configuration'],
        'status' => 200
      ]);
      return;
    }

    $oai_url = rtrim($oai_url, '/');
    if (!preg_match('/\/v\d+\/?$/', $oai_url)) { $oai_url .= '/v1'; }

    // 👉 renvoie le proxy même-origine (le proxy appellera /v1/audio/speech côté serveur)
    $successResponse = [
      'response' => [
        'data' => [
          'oai_url' => Minz_Url::display('/p/ext.php?f=xExtension-ArticleSummary/proxy_tts'),
          'oai_key' => '', // éviter Bearer undefined côté JS, le proxy n’utilise pas ce header
          'model' => $tts_model,
          'voice' => $voice,
          'speed' => $speed,
          'input' => $content,
          'stream' => true,
          'response_format' => 'opus',
        ],
        'provider' => 'openai',
        'error' => null
      ],
      'status' => 200
    ];

    echo json_encode($successResponse);
    return;
  }

  public function fetchTtsParamsAction()
  {
    $this->view->_layout(false);
    header('Content-Type: application/json');

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $tts_model = FreshRSS_Context::$user_conf->oai_tts_model;
    $voice = FreshRSS_Context::$user_conf->oai_voice;
    $speed = FreshRSS_Context::$user_conf->oai_speed;
    if ($speed === null || !is_numeric($speed)) { $speed = 1.1; }
    $speed = max(0.5, min(4, (float)$speed));

    if (
      $this->isEmpty($oai_url) ||
      $this->isEmpty($oai_key) ||
      $this->isEmpty($tts_model) ||
      $this->isEmpty($voice)
    ) {
      echo json_encode([
        'response' => ['data' => 'missing config', 'error' => 'configuration'],
        'status' => 200
      ]);
      return;
    }

    // 👉 renvoie l’URL du proxy (même-origine) + pas de clé côté client
    $successResponse = [
      'response' => [
        'data' => [
          'oai_url' => Minz_Url::display('/p/ext.php', 'f=xExtension-ArticleSummary/proxy_tts'),
          'oai_key' => '', // le proxy utilisera la clé serveur
          'model' => $tts_model,
          'voice' => $voice,
          'speed' => $speed,
          'response_format' => 'opus',
        ],
        'error' => null
      ],
      'status' => 200
    ];

    echo json_encode($successResponse);
    return;
  }

  private function isEmpty($item)
  {
    return $item === null || trim($item) === '';
  }

  private function htmlToMarkdown($content)
  {
    // Ensure DOM extension is available; otherwise fall back to plain text
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
      return trim(strip_tags($content));
    }

    // Creating DOMDocument objects
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath objects
    $xpath = new DOMXPath($dom);

    // Define an anonymous function to process the node
    $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
      $markdown = '';

      // Processing text nodes
      if ($node->nodeType === XML_TEXT_NODE) {
        $markdown .= trim($node->nodeValue);
      }

      // Processing element nodes
      if ($node->nodeType === XML_ELEMENT_NODE) {
        switch ($node->nodeName) {
          case 'p':
          case 'div':
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            $markdown .= "\n\n";
            break;
          case 'h1':
            $markdown .= "# ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h2':
            $markdown .= "## ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h3':
            $markdown .= "### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h4':
            $markdown .= "#### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h5':
            $markdown .= "##### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'h6':
            $markdown .= "###### ";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "\n\n";
            break;
          case 'a':
            // $markdown .= "[";
            // $markdown .= $processNode($node->firstChild);
            // $markdown .= "](" . $node->getAttribute('href') . ")";
            $markdown .= "`";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "`";
            break;
          case 'img':
            $alt = $node->getAttribute('alt');
            $markdown .= "img: `" . $alt . "`";
            break;
          case 'strong':
          case 'b':
            $markdown .= "**";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "**";
            break;
          case 'em':
          case 'i':
            $markdown .= "*";
            $markdown .= $processNode($node->firstChild);
            $markdown .= "*";
            break;
          case 'ul':
          case 'ol':
            $markdown .= "\n";
            foreach ($node->childNodes as $child) {
              if ($child->nodeName === 'li') {
                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                $markdown .= $processNode($child, $indentLevel + 1);
                $markdown .= "\n";
              }
            }
            $markdown .= "\n";
            break;
          case 'li':
            $markdown .= str_repeat("  ", $indentLevel) . "- ";
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child, $indentLevel + 1);
            }
            $markdown .= "\n";
            break;
          case 'br':
            $markdown .= "\n";
            break;
          case 'audio':
          case 'video':
            $alt = $node->getAttribute('alt');
            $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
            break;
          default:
            // Tags not considered, only the text inside is kept
            foreach ($node->childNodes as $child) {
              $markdown .= $processNode($child);
            }
            break;
        }
      }

      return $markdown;
    };

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $processNode($node);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);
    
    return $markdown;
  }

}
