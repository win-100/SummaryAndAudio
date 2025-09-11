<?php

class FreshExtension_ArticleSummary_proxy_tts_Action extends Minz_ActionController {
  public function index() {
    $this->view->_layout(false);

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
      header('Content-Type: application/json');
      echo json_encode(['error' => 'bad_payload']);
      return;
    }

    $apiKey = FreshRSS_Context::$user_conf->oai_key ?? '';
    if (empty($apiKey)) {
      header('Content-Type: application/json');
      echo json_encode(['error' => 'missing_server_key']);
      return;
    }

    // Appel OpenAI /v1/audio/speech
    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
      ],
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => true,
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
      header('Content-Type: application/json');
      echo json_encode(['error' => 'tts_upstream_failed']);
      return;
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);

    // Détecte le Content-Type de la réponse OpenAI
    $contentType = 'application/octet-stream';
    if (preg_match('~^Content-Type:\s*([^\r\n]+)~mi', $headers, $m)) {
      $contentType = trim($m[1]);
    }

    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-cache');
    // Tu peux aussi ajouter: header('Content-Length: ' . strlen($body));
    echo $body;
  }
}
