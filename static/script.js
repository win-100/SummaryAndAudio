if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

function configureSummarizeButtons() {
  document.getElementById('global').addEventListener('click', function (e) {
    for (var target = e.target; target && target != this; target = target.parentNode) {
      if (target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          summarizeButtonClick(target);
        }
        break;
      }
      if (target.matches('.oai-tts-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          ttsButtonClick(target);
        }
        break;
      }
    }
  }, false);
}

function setOaiState(container, statusType, statusMsg, summaryText) {
  const button = container.querySelector('.oai-summary-btn');
  const moreButton = container.querySelector('.oai-summary-more');
  const content = container.querySelector('.oai-summary-content');
  const log = container.querySelector('.oai-summary-log');
  if (statusMsg !== null) {
    if (statusMsg === 'finish') {
      log.textContent = '';
      log.style.display = 'none';
    } else {
      log.textContent = statusMsg;
      log.style.display = 'block';
    }
  }
  if (statusType === 1) {
    container.classList.add('oai-loading');
    container.classList.remove('oai-error');
    button.disabled = true;
    content.innerHTML = '';
    if (moreButton) moreButton.style.display = 'none';
  } else if (statusType === 2) {
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    button.disabled = false;
    content.innerHTML = '';
    if (moreButton) moreButton.style.display = 'none';
  } else {
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish') {
      button.disabled = false;
      if (container.dataset.moreUsed) {
        if (moreButton) {
          moreButton.remove();
        }
        delete container.dataset.moreUsed;
      } else if (moreButton) {
        moreButton.style.display = 'inline-block';
      }
    }
  }

  if (summaryText) {
    content.innerHTML = summaryText;
  }
}

async function summarizeButtonClick(target) {
  var container = target.closest('.oai-summary-wrap');
  var t = container.dataset;
  if (container.classList.contains('oai-loading')) {
    return;
  }

  if (target.classList.contains('oai-summary-more')) {
    container.dataset.moreUsed = '1';
  }

  container.classList.add('oai-summary-active');

  setOaiState(container, 1, t.preparingRequest, null);

  var url = target.dataset.request;
  var data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: data
    });

    if (!response.ok) {
      throw new Error(t.requestFailed + ' (1)');
    }

    const provider = response.headers.get('X-Summary-Provider') || 'openai';
    setOaiState(container, 1, provider === 'ollama' ? t.pendingOllama : t.pendingOpenai, null);
    await streamSummary(container, response, provider);
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (2)', null);
  }
}

async function streamSummary(container, response, provider) {
  const t = container.dataset;
  try {
    setOaiState(container, 1, t.receivingAnswer, null);
    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';
    if (provider === 'ollama') {
      while (true) {
        const { done, value } = await reader.read();
        if (done) {
          setOaiState(container, 0, 'finish', null);
          break;
        }
        buffer += decoder.decode(value, { stream: true });
        let endIndex;
        while ((endIndex = buffer.indexOf('\n')) !== -1) {
          const jsonString = buffer.slice(0, endIndex).trim();
          buffer = buffer.slice(endIndex + 1);
          if (!jsonString) continue;
          try {
            const json = JSON.parse(jsonString);
            if (json.response) {
              text += json.response;
              setOaiState(container, 0, null, marked.parse(text));
            }
          } catch (e) {
            console.error('Error parsing JSON:', e, 'Chunk:', jsonString);
          }
        }
      }
    } else {
      while (true) {
        const { done, value } = await reader.read();
        if (done) {
          if (buffer.trim()) {
            try {
              const json = JSON.parse(buffer.trim());
              if (json.output_text) {
                text += json.output_text;
                setOaiState(container, 0, null, marked.parse(text));
              }
            } catch (e) {
              console.error('Error parsing final JSON:', e, 'Chunk:', buffer);
              setOaiState(container, 2, t.requestFailed + ' (3)', null);
            }
          }
          setOaiState(container, 0, 'finish', null);
          break;
        }
        buffer += decoder.decode(value, { stream: true });
        let parts = buffer.split(/\n\n/);
        buffer = parts.pop();
        for (let part of parts) {
          const lines = part.trim().split('\n');
          const dataLine = lines.find(l => l.startsWith('data:'));
          if (!dataLine) continue;
          let data = dataLine.slice(5).trim();
          if (data === '[DONE]') {
            setOaiState(container, 0, 'finish', null);
            return;
          }
          try {
            const json = JSON.parse(data);
            if (json.type === 'response.completed') {
              setOaiState(container, 0, 'finish', null);
              return;
            }
            const delta = json.delta || json.output_text || '';
            if (delta) {
              text += delta;
              setOaiState(container, 0, null, marked.parse(text));
            }
          } catch (e) {
            console.error('Error parsing JSON:', e, 'Chunk:', data);
          }
        }
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (4)', null);
  }
}

async function ttsButtonClick(target, forceStop = false, preload = false) {
  const container = target.closest('.oai-summary-wrap');
  const log = container.querySelector('.oai-summary-log');
  const t = container.dataset;
  const readLabel = t.read;
  const pauseLabel = t.pause;
  const msgPrepAudio = t.preparingAudio;
  const msgAudioFailed = t.audioFailed;
  const msgRequestFailed = t.requestFailed;

  const maybePreloadNext = (btn) => {
    const parent = btn._sequenceParent;
    if (!parent || !parent._sequence) return;
    const seq = parent._sequence;
    const nextBtn = seq.buttons[seq.index];
    if (nextBtn && !nextBtn._audio) {
      ttsButtonClick(nextBtn, false, true);
    }
  };

  // Global article button: handle sequential paragraph reading
  if (!target.classList.contains('oai-tts-paragraph')) {
    if (target._sequence) {
      const currentBtn = target._sequence.currentBtn;
      if (currentBtn) {
        await ttsButtonClick(currentBtn);
        if (currentBtn._audio && !currentBtn._audio.paused) {
          target.classList.add('oai-playing');
          target.setAttribute('aria-label', pauseLabel);
          target.setAttribute('title', pauseLabel);
        } else {
          target.classList.remove('oai-playing');
          target.setAttribute('aria-label', readLabel);
          target.setAttribute('title', readLabel);
        }
      }
      return;
    }

    const buttons = Array.from(container.querySelectorAll('.oai-tts-paragraph'));
    if (buttons.length === 0) {
      return;
    }
    target._sequence = { buttons: buttons, index: 0, currentBtn: null };
    target.classList.add('oai-playing');
    target.setAttribute('aria-label', pauseLabel);
    target.setAttribute('title', pauseLabel);
    target._playNextParagraph = function () {
      const seq = target._sequence;
      if (!seq || seq.index >= seq.buttons.length) {
        target.classList.remove('oai-playing');
        target.setAttribute('aria-label', readLabel);
        target.setAttribute('title', readLabel);
        target._sequence = null;
        log.textContent = '';
        log.style.display = 'none';
        return;
      }
      const btn = seq.buttons[seq.index++];
      seq.currentBtn = btn;
      btn._sequenceParent = target;
      ttsButtonClick(btn);
    };
    target._playNextParagraph();
    return;
  }

  // Paragraph button: start sequence from this paragraph
  const articleBtn = container.querySelector('.oai-tts-btn:not(.oai-tts-paragraph)');
  if (articleBtn && !target._sequenceParent && !preload) {
    if (
      articleBtn._sequence &&
      articleBtn._sequence.currentBtn &&
      articleBtn._sequence.currentBtn !== target
    ) {
      await ttsButtonClick(articleBtn._sequence.currentBtn, true);
    }
    const buttons = Array.from(container.querySelectorAll('.oai-tts-paragraph'));
    articleBtn._sequence = {
      buttons: buttons,
      index: buttons.indexOf(target) + 1,
      currentBtn: target
    };
    articleBtn.classList.add('oai-playing');
    articleBtn.setAttribute('aria-label', pauseLabel);
    articleBtn.setAttribute('title', pauseLabel);
    articleBtn._playNextParagraph = function () {
      const seq = articleBtn._sequence;
      if (!seq || seq.index >= seq.buttons.length) {
        articleBtn.classList.remove('oai-playing');
        articleBtn.setAttribute('aria-label', readLabel);
        articleBtn.setAttribute('title', readLabel);
        articleBtn._sequence = null;
        log.textContent = '';
        log.style.display = 'none';
        return;
      }
      const btn = seq.buttons[seq.index++];
      seq.currentBtn = btn;
      btn._sequenceParent = articleBtn;
      ttsButtonClick(btn);
    };
    target._sequenceParent = articleBtn;
  }

  // Toggle play/pause or cancel if audio already loaded for paragraph button
  if (target._audio) {
    if (preload) {
      return;
    }
    if (forceStop) {
      target._audio.pause();
      target._audio.src = '';
      target._audio = null;
      log.textContent = '';
      log.style.display = 'none';
      target.classList.remove('oai-playing');
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
      if (target._sequenceParent) {
        const parent = target._sequenceParent;
        target._sequenceParent = null;
        parent.classList.remove('oai-playing');
        parent.setAttribute('aria-label', readLabel);
        parent.setAttribute('title', readLabel);
        parent._sequence = null;
      }
      return;
    }

    if (target._audio.paused) {
      try {
        await target._audio.play();
        target.classList.add('oai-playing');
        target.setAttribute('aria-label', pauseLabel);
        target.setAttribute('title', pauseLabel);
        log.textContent = '';
        log.style.display = 'none';
        maybePreloadNext(target);
      } catch (err) {
        console.error('Playback failed', err);
        log.textContent = msgAudioFailed;
        log.style.display = 'block';
        target.classList.remove('oai-playing');
        target.setAttribute('aria-label', readLabel);
        target.setAttribute('title', readLabel);
        target._audio.addEventListener(
          'canplay',
          async () => {
            try {
              await target._audio.play();
              target.classList.add('oai-playing');
              target.setAttribute('aria-label', pauseLabel);
              target.setAttribute('title', pauseLabel);
              log.textContent = '';
              log.style.display = 'none';
              maybePreloadNext(target);
            } catch (err2) {
              console.error('Playback retry failed', err2);
            }
          },
          { once: true }
        );
        return;
      }
    } else {
      target._audio.pause();
      target.classList.remove('oai-playing');
      target.setAttribute('aria-label', readLabel);
      target.setAttribute('title', readLabel);
    }
    if (target._sequenceParent) {
      const parent = target._sequenceParent;
      if (target._audio && !target._audio.paused) {
        parent.classList.add('oai-playing');
        parent.setAttribute('aria-label', pauseLabel);
        parent.setAttribute('title', pauseLabel);
      } else {
        parent.classList.remove('oai-playing');
        parent.setAttribute('aria-label', readLabel);
        parent.setAttribute('title', readLabel);
        if (!target._audio) {
          parent._sequence = null;
        }
      }
    }
    return;
  }

  let text;
  if (target.classList.contains('oai-tts-paragraph')) {
    const p = target.closest('p');
    text = p ? p.textContent.trim() : '';
  } else {
    const article = container.querySelector('.oai-summary-article');
    text = article ? article.textContent.trim() : '';
  }
  if (!text) {
    return;
  }

  const url = target.dataset.request;
  const ttsClientUrl = container.dataset.ttsClientUrl;
  const form = new URLSearchParams();
  form.append('ajax', 'true');
  form.append('_csrf', context.csrf);
  form.append('content', text);

  const testAudio = document.createElement('audio');
  let responseFormat = 'opus';
  if (!testAudio.canPlayType('audio/ogg; codecs=opus')) {
    if (testAudio.canPlayType('audio/mpeg')) {
      responseFormat = 'mp3';
    } else if (testAudio.canPlayType('audio/ogg')) {
      responseFormat = 'ogg';
    }
  }
  form.append('format', responseFormat);

  if (!preload) {
    target.disabled = true;
    log.textContent = msgPrepAudio;
    log.style.display = 'block';
  }
  try {
    const audio = target._audio || document.createElement('audio');
    if (!target._audio) {
      if (ttsClientUrl) {
        const model = container.dataset.ttsClientModel;
        const voice = container.dataset.ttsClientVoice;
        if (!model || !voice) {
          throw new Error('Invalid TTS parameters');
        }
        const response = await fetch(ttsClientUrl + '/audio/speech', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            model: model,
            voice: voice,
            speed: Number(container.dataset.ttsClientSpeed),
            input: text,
            format: responseFormat
          })
        });
        if (!response.ok) {
          throw new Error('TTS request failed');
        }
        target._audioUrl = URL.createObjectURL(await response.blob());
        audio.src = target._audioUrl;
      } else {
        const qs = url.includes('?') ? '&' : '?';
        const audioUrl = url + qs + form.toString();
        audio.src = audioUrl;
      }
      audio.preload = 'auto';
      audio.load();
      audio.addEventListener('ended', () => {
        target.classList.remove('oai-playing');
        target.setAttribute('aria-label', readLabel);
        target.setAttribute('title', readLabel);
        if (target._sequenceParent) {
          const parent = target._sequenceParent;
          target._sequenceParent = null;
          parent._playNextParagraph();
        }
      });
      audio.addEventListener(
        'error',
        () => {
          log.textContent = msgAudioFailed;
          log.style.display = 'block';
          target.classList.remove('oai-playing');
          target.setAttribute('aria-label', readLabel);
          target.setAttribute('title', readLabel);
          if (target._sequenceParent) {
            const parent = target._sequenceParent;
            target._sequenceParent = null;
            parent._playNextParagraph();
          }
        },
        { once: true }
      );
    }
    target._audio = audio;

    if (!preload) {
      try {
        await audio.play();
        target.classList.add('oai-playing');
        target.setAttribute('aria-label', pauseLabel);
        target.setAttribute('title', pauseLabel);
        log.textContent = '';
        log.style.display = 'none';
        maybePreloadNext(target);
      } catch (err) {
        console.error('Playback failed', err);
        log.textContent = msgAudioFailed;
        log.style.display = 'block';
        target.classList.remove('oai-playing');
        target.setAttribute('aria-label', readLabel);
        target.setAttribute('title', readLabel);
      }
    }
  } catch (err) {
    console.error(err);
    if (!preload) {
      log.textContent = msgAudioFailed;
      log.style.display = 'block';
    }
    target._audio = null;
  } finally {
    if (!preload) {
      target.disabled = false;
    }
  }
}
