<?php

class xExtension_ArticleSummary_proxy_responses_Action extends Minz_ActionController {
  public function index() {
    // Désactive le layout / toute sortie HTML
    $this->view->_layout(false);

    // Récupère le JSON envoyé par ton JS (le champ "payload" de summarizeAction)
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
      // Si le JS t’envoie l’enveloppe { payload: {...} } tu peux t’adapter ici
      $payload = [];
    }

    // Récupère la clé côté serveur (ne jamais l’envoyer au client)
    // Si tu l’enregistres dans la conf utilisateur :
    $apiKey = FreshRSS_Context::$user_conf->oai_key ?? '';
    if (empty($apiKey)) {
      header('Content-Type: application/json');
      echo json_encode(['error' => 'missing_server_key']);
      return;
    }

    // Headers SSE + no buffering
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    // Appel OpenAI /v1/responses en streaming
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
      ],
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
        // On forward tel quel : OpenAI émet déjà des SSE "data: {...}\n\n"
        echo $chunk;
        @ob_flush(); flush();
        return strlen($chunk);
      },
      CURLOPT_TIMEOUT => 0,
    ]);

    curl_exec($ch);
    curl_close($ch);
    // Ne rien émettre d’autre après
  }
}
