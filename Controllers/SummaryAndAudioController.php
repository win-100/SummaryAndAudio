<?php

class FreshExtension_SummaryAndAudio_Controller extends Minz_ActionController
{
  public function summarizeAction()
  {
    $this->view->_layout(false);

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt_base = FreshRSS_Context::$user_conf->oai_prompt;
    $oai_prompt_more = FreshRSS_Context::$user_conf->oai_prompt_2;
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider;
    $use_more = Minz_Request::param('more');
    $oai_prompt = $use_more ? $oai_prompt_more : $oai_prompt_base;

    if (
      $this->isEmpty($oai_url) ||
      $this->isEmpty($oai_key) ||
      $this->isEmpty($oai_model) ||
      $this->isEmpty($oai_prompt)
    ) {
      header('Content-Type: application/json');
      echo json_encode(array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      ));
      return;
    }

    $entry_id = Minz_Request::param('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      header('Content-Type: application/json');
      echo json_encode(array('status' => 404));
      return;
    }

    $content = $entry->content();
    $markdownContent = $this->htmlToMarkdown($content);

    $oai_url = rtrim($oai_url, '/');
    if ($oai_provider !== 'ollama' && !preg_match('/\/v\d+\/?$/', $oai_url)) {
      $oai_url .= '/v1';
    }

    $headers = [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $oai_key,
    ];

    if ($oai_provider === 'ollama') {
      $payload = json_encode([
        'model' => $oai_model,
        'system' => $oai_prompt,
        'prompt' => $markdownContent,
        'stream' => true,
      ]);
      $summaryUrl = rtrim($oai_url, '/') . '/api/generate';
    } else {
      $payload = json_encode([
        'model' => $oai_model,
        'input' => [
          [ 'role' => 'system', 'content' => $oai_prompt ],
          [ 'role' => 'user', 'content' => "input: \n" . $markdownContent ],
        ],
        'reasoning' => [ 'effort' => 'minimal' ],
        'max_output_tokens' => 2048,
        'temperature' => 1,
        'stream' => true,
      ]);
      $summaryUrl = $oai_url . '/responses';
    }

    header('X-Summary-Provider: ' . $oai_provider);

    $headersSent = false;
    $statusCode = 0;
    $respContentType = '';
    $errorBody = '';

    $ch = curl_init($summaryUrl);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$statusCode, &$respContentType) {
        $len = strlen($header);
        if (preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#', $header, $m)) {
          $statusCode = (int)$m[1];
        } elseif (stripos($header, 'Content-Type:') === 0) {
          $respContentType = trim(substr($header, 13));
        }
        return $len;
      },
      CURLOPT_WRITEFUNCTION => function ($curl, $data) use (&$headersSent, &$statusCode, &$respContentType, &$errorBody) {
        if (!$headersSent) {
          if ($statusCode >= 200 && $statusCode < 300) {
            $type = $respContentType ?: 'text/plain';
            header('Content-Type: ' . $type);
            header('Cache-Control: no-cache');
          } else {
            header('Content-Type: application/json', true, $statusCode ?: 500);
          }
          $headersSent = true;
        }
        if ($statusCode >= 200 && $statusCode < 300) {
          echo $data;
          if (function_exists('ob_flush')) {
            @ob_flush();
          }
          flush();
        } else {
          $errorBody .= $data;
        }
        return strlen($data);
      },
    ]);

    $result = curl_exec($ch);
    if ($result === false && !$headersSent) {
      $errorBody = curl_error($ch);
    }
    curl_close($ch);

    if ($result === false || $statusCode < 200 || $statusCode >= 300) {
      if (!$headersSent) {
        header('Content-Type: application/json', true, $statusCode ?: 500);
      }
      $msg = 'Summary request failed';
      $decoded = json_decode($errorBody, true);
      if ($decoded && isset($decoded['error']['message'])) {
        $msg = $decoded['error']['message'];
      }
      echo json_encode(array(
        'response' => array(
          'data' => '',
          'error' => $msg,
        ),
        'status' => $statusCode ?: 500,
      ));
    }
    return;
  }

  public function speakAction()
  {
    $this->view->_layout(false);

    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $tts_base_url = FreshRSS_Context::$user_conf->oai_tts_url ?? '';
    if ($this->isEmpty($tts_base_url)) {
      $tts_base_url = $oai_url;
    }
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $tts_model = FreshRSS_Context::$user_conf->oai_tts_model;
    $voice = FreshRSS_Context::$user_conf->oai_voice;
    $speed = FreshRSS_Context::$user_conf->oai_speed;
    if ($speed === null || !is_numeric($speed)) {
      $speed = 1.1;
    }
    $speed = max(0.5, min(4, (float)$speed));
    $content = Minz_Request::param('content');
    $format = Minz_Request::param('format');
    $format = in_array($format, ['mp3', 'ogg', 'opus']) ? $format : 'opus';

    if (
      $this->isEmpty($tts_base_url) ||
      $this->isEmpty($tts_model) ||
      $this->isEmpty($voice) ||
      $this->isEmpty($content)
    ) {
      header('Content-Type: application/json');
      echo json_encode(array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      ));
      return;
    }

    $tts_base_url = rtrim($tts_base_url, '/');
    if (!preg_match('/\/v\d+\/?$/', $tts_base_url)) {
      $tts_base_url .= '/v1';
    }
    $tts_url = $tts_base_url . '/audio/speech';

    $headersSent = false;
    $statusCode = 0;
    $respContentType = '';
    $errorBody = '';

    $ch = curl_init($tts_url);
    $payload = json_encode([
      'model' => $tts_model,
      'voice' => $voice,
      'speed' => $speed,
      'input' => $content,
      'format' => $format,
    ]);
    $headers = ['Content-Type: application/json'];
    if (!$this->isEmpty($oai_key)) {
      $headers[] = 'Authorization: Bearer ' . $oai_key;
    }
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$statusCode, &$respContentType) {
        $len = strlen($header);
        if (preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#', $header, $m)) {
          $statusCode = (int)$m[1];
        } elseif (stripos($header, 'Content-Type:') === 0) {
          $respContentType = trim(substr($header, 13));
        }
        return $len;
      },
      CURLOPT_WRITEFUNCTION => function ($curl, $data) use (&$headersSent, &$statusCode, &$respContentType, &$errorBody) {
        if (!$headersSent) {
          if ($statusCode >= 200 && $statusCode < 300) {
            $type = $respContentType ?: 'audio/ogg';
            header('Content-Type: ' . $type);
            header('Cache-Control: no-cache');
          } else {
            header('Content-Type: application/json', true, $statusCode ?: 500);
          }
          $headersSent = true;
        }
        if ($statusCode >= 200 && $statusCode < 300) {
          echo $data;
          if (function_exists('ob_flush')) {
            @ob_flush();
          }
          flush();
        } else {
          $errorBody .= $data;
        }
        return strlen($data);
      },
    ]);

    $result = curl_exec($ch);
    if ($result === false && !$headersSent) {
      $errorBody = curl_error($ch);
    }
    curl_close($ch);

    if ($result === false || $statusCode < 200 || $statusCode >= 300) {
      if (!$headersSent) {
        header('Content-Type: application/json', true, $statusCode ?: 500);
      }
      $msg = 'Audio request failed';
      $decoded = json_decode($errorBody, true);
      if ($decoded && isset($decoded['error']['message'])) {
        $msg = $decoded['error']['message'];
      }
      echo json_encode(array(
        'response' => array(
          'data' => '',
          'error' => $msg,
        ),
        'status' => $statusCode ?: 500,
      ));
    }
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
