<?php
class ArticleSummaryExtension extends Minz_Extension
{
  private static ?array $i18n = null;

  public function init()
  {
    $this->registerHook('entry_before_display', array($this, 'addSummaryButton'));
    $this->registerController('ArticleSummary');
    Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
    Minz_View::appendScript($this->getFileUrl('axios.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('marked.js', 'js'));
    Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
  }

  public function addSummaryButton($entry)
  {
    $url_summary = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'summarize',
      'params' => array(
        'id' => $entry->id()
      )
    ));
    $url_more = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'summarize',
      'params' => array(
        'id' => $entry->id(),
        'more' => 1
      )
    ));
    $has_more = trim((string)FreshRSS_Context::$user_conf->oai_prompt_2) !== '';

    $url_tts = Minz_Url::display(array(
      'c' => 'ArticleSummary',
      'a' => 'speak'
    ));
    $icon_tts_play = str_replace('<svg ', '<svg class="oai-tts-icon oai-tts-play" ', file_get_contents(__DIR__ . '/static/img/play.svg'));
    $icon_tts_pause = str_replace('<svg ', '<svg class="oai-tts-icon oai-tts-pause" ', file_get_contents(__DIR__ . '/static/img/pause.svg'));
    $icon = str_replace('<svg ', '<svg class="oai-summary-icon" ', file_get_contents(__DIR__ . '/static/img/summary.svg'));

    $paragraph_button = '<button data-request="' . $url_tts . '" class="oai-tts-btn oai-tts-paragraph" aria-label="' . self::t('read_paragraph') . '" title="' . self::t('read_paragraph') . '">'
      . $icon_tts_play . $icon_tts_pause . '</button>';
    $article_content = preg_replace('/<p\b([^>]*)>/', '<p$1>' . $paragraph_button, $entry->content());
    $attrs = [
      'data-read' => self::t('read'),
      'data-pause' => self::t('pause'),
      'data-preparing-request' => self::t('preparing_request'),
      'data-pending-openai' => self::t('pending_openai'),
      'data-pending-ollama' => self::t('pending_ollama'),
      'data-preparing-audio' => self::t('preparing_audio'),
      'data-audio-failed' => self::t('audio_failed'),
      'data-receiving-answer' => self::t('receiving_answer'),
      'data-request-failed' => self::t('request_failed'),
      'data-proxy' => Minz_Url::display(array(
        'c' => 'ArticleSummary',
        'a' => 'proxy'
      )),
    ];
    $attr_str = '';
    foreach ($attrs as $name => $value) {
      $attr_str .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
    }

    $entry->_content(
        '<div class="oai-summary-wrap"' . $attr_str . '>'
        . '<button data-request="' . $url_tts . '" class="oai-tts-btn btn btn-small" aria-label="' . self::t('read') . '" title="' . self::t('read') . '">' . $icon_tts_play . $icon_tts_pause . '</button>'
        . '<button data-request="' . $url_summary . '" class="oai-summary-btn btn btn-small" aria-label="' . self::t('summarize') . '" title="' . self::t('summarize') . '">'
        . $icon . '</button>'
        . '<div class="oai-summary-box">'
        . '<div class="oai-summary-loader"></div>'
        . '<div class="oai-summary-log"></div>'
        . '<div class="oai-summary-content"></div>'
        . ($has_more ? '<button data-request="' . $url_more . '" class="oai-summary-btn oai-summary-more btn btn-small" aria-label="' . self::t('longer_summary') . '" title="' . self::t('longer_summary') . '">+</button>' : '')
        . '</div>'
        . '<div class="oai-summary-article">' . $article_content . '</div>'
        . '</div>'
      );
    return $entry;
  }

  public static function t(string $key): string
  {
    if (self::$i18n === null) {
      $lang = FreshRSS_Context::$user_conf->language ?? 'en';
      $file = __DIR__ . '/i18n/' . $lang . '.php';
      if (!is_file($file)) {
        $file = __DIR__ . '/i18n/en.php';
      }
      self::$i18n = include $file;
    }
    return self::$i18n[$key] ?? $key;
  }

  public function handleConfigureAction()
  {
    if (Minz_Request::isPost()) {
      FreshRSS_Context::$user_conf->oai_url = Minz_Request::param('oai_url', '');
      FreshRSS_Context::$user_conf->oai_key = Minz_Request::param('oai_key', '');
      FreshRSS_Context::$user_conf->oai_model = Minz_Request::param('oai_model', '');
      FreshRSS_Context::$user_conf->oai_prompt = Minz_Request::param('oai_prompt', '');
      FreshRSS_Context::$user_conf->oai_prompt_2 = Minz_Request::param('oai_prompt_2', '');
      FreshRSS_Context::$user_conf->oai_provider = Minz_Request::param('oai_provider', '');
      FreshRSS_Context::$user_conf->oai_tts_model = Minz_Request::param('oai_tts_model', '');
      FreshRSS_Context::$user_conf->oai_voice = Minz_Request::param('oai_voice', '');
      $speed = (float)Minz_Request::param('oai_speed', 1.1);
      if ($speed < 0.5 || $speed > 4) {
        $speed = 1.1;
      }
      FreshRSS_Context::$user_conf->oai_speed = $speed;
      FreshRSS_Context::$user_conf->save();
    }
  }
}
