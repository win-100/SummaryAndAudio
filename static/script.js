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

  // This is the address where PHP gets the parameters
  var url = target.dataset.request;
  var data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);

  try {
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      }
    });

    const xresp = response.data;
    console.log(xresp);

    if (response.status !== 200 || !xresp.response || !xresp.response.data) {
      throw new Error(t.requestFailed + ' (1)');
    }

    if (xresp.response.error) {
      setOaiState(container, 2, xresp.response.data, null);
    } else {
      const oaiParams = xresp.response.data;
      const oaiProvider = xresp.response.provider;
      setOaiState(container, 1, oaiProvider === 'openai' ? t.pendingOpenai : t.pendingOllama, null);
      if (oaiProvider === 'openai') {
        await sendOpenAIRequest(container, oaiParams);
      } else {
        await sendOllamaRequest(container, oaiParams);
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (2)', null);
  }
}

function getProxyUrl(container) {
  return container.dataset.proxy;
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
    if (nextBtn && !nextBtn._audio && !nextBtn._abortController) {
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
      if (target._abortController) {
        target._abortController.abort();
        target._abortController = null;
        URL.revokeObjectURL(target._audio.src);
      }
      target._audio.pause();
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
      if (target._abortController) {
        target._abortController.abort();
        target._abortController = null;
        URL.revokeObjectURL(target._audio.src);
        target._audio.pause();
        target._audio = null;
        log.textContent = '';
        log.style.display = 'none';
      } else {
        target._audio.pause();
      }
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
  const data = new URLSearchParams();
  data.append('ajax', 'true');
  data.append('_csrf', context.csrf);
  data.append('content', text);

  if (!preload) {
    target.disabled = true;
    log.textContent = msgPrepAudio;
    log.style.display = 'block';
  }

  try {
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      }
    });

    const xresp = response.data;
    if (response.status !== 200 || !xresp.response || !xresp.response.data || xresp.response.error) {
      throw new Error(msgRequestFailed);
    }

    const params = xresp.response.data;
    const body = {
      model: params.model,
      voice: params.voice,
      speed: params.speed,
      input: params.input,
      stream: params.stream,
      response_format: params.response_format
    };

    if (!('MediaSource' in window) || !MediaSource.isTypeSupported('audio/ogg; codecs=opus')) {
      const testAudio = document.createElement('audio');
      if (testAudio.canPlayType('audio/mpeg')) {
        body.response_format = 'mp3';
      } else if (testAudio.canPlayType('audio/ogg; codecs=opus')) {
        body.response_format = 'ogg';
      }
    }

    const controller = new AbortController();
    target._abortController = controller;

    const proxyUrl = getProxyUrl(container);
    const proxyParams = new URLSearchParams();
    proxyParams.append('ajax', 'true');
    proxyParams.append('_csrf', context.csrf);
    proxyParams.append('payload', JSON.stringify({
      url: params.oai_url,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${params.oai_key}`
      },
      body: body
    }));
    const audioResp = await fetch(proxyUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: proxyParams,
      signal: controller.signal
    });

    if (!audioResp.ok) {
      throw new Error('Audio request failed');
    }

    const mimeType = audioResp.headers.get('Content-Type') || 'audio/ogg';
    let sourceType = mimeType.includes('ogg') ? 'audio/ogg; codecs=opus' : mimeType;
    if (mimeType === 'audio/opus') {
      sourceType = 'audio/ogg; codecs=opus';
    }

    const testAudio = document.createElement('audio');
    const mseSupported = typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported(sourceType);
    if (!mseSupported) {
      if (testAudio.canPlayType(sourceType)) {
        const blobUrl = URL.createObjectURL(await audioResp.blob());
        const audio = new Audio(blobUrl);
        target._audio = audio;
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
        if (!preload) {
          target.classList.add('oai-playing');
          target.setAttribute('aria-label', pauseLabel);
          target.setAttribute('title', pauseLabel);
          log.textContent = '';
          log.style.display = 'none';
        }
        target._abortController = null;
        if (!preload) {
          try {
            await audio.play();
            maybePreloadNext(target);
          } catch (err) {
            console.error('Playback failed', err);
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
            audio.addEventListener(
              'canplay',
              async () => {
                try {
                  await audio.play();
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
          }
        }
        return;
      }
      throw new Error('Unsupported audio format');
    }
    const mediaSource = new MediaSource();
    const audioUrl = URL.createObjectURL(mediaSource);
    const audio = new Audio(audioUrl);
    target._audio = audio;
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

    mediaSource.addEventListener('sourceopen', async () => {
      const sourceBuffer = mediaSource.addSourceBuffer(sourceType);
      const reader = audioResp.body.getReader();
      let started = false;
      try {
        while (true) {
          const { done, value } = await reader.read();
          if (done) {
            sourceBuffer.addEventListener('updateend', () => mediaSource.endOfStream(), { once: true });
            break;
          }
          sourceBuffer.appendBuffer(value);
          await new Promise(res => sourceBuffer.addEventListener('updateend', res, { once: true }));
          if (!started && !preload) {
            try {
              await audio.play();
              target.classList.add('oai-playing');
              target.setAttribute('aria-label', pauseLabel);
              target.setAttribute('title', pauseLabel);
              log.textContent = '';
              log.style.display = 'none';
              maybePreloadNext(target);
              started = true;
            } catch (err) {
              console.error('Playback failed', err);
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
              audio.addEventListener(
                'canplay',
                async () => {
                  try {
                    await audio.play();
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
            }
          }
        }
      } catch (err) {
        if (err.name !== 'AbortError') {
          console.error(err);
          if (!preload) {
            log.textContent = 'Audio failed';
            log.style.display = 'block';
            target.classList.remove('oai-playing');
            target.setAttribute('aria-label', readLabel);
            target.setAttribute('title', readLabel);
          }
        }
      } finally {
        target._abortController = null;
      }
    });
  } catch (err) {
    console.error(err);
    if (!preload) {
      log.textContent = err.name === 'AbortError' ? 'Audio canceled' : 'Audio failed';
      log.style.display = 'block';
      target._audio = null;
      target._abortController = null;
      if (target._sequenceParent) {
        const parent = target._sequenceParent;
        target._sequenceParent = null;
        parent._playNextParagraph();
      }
    } else {
      target._audio = null;
      target._abortController = null;
    }
  } finally {
    if (!preload) {
      target.disabled = false;
    }
  }
}

async function sendOpenAIRequest(container, oaiParams) {
  const t = container.dataset;
  try {
    let body = JSON.parse(JSON.stringify(oaiParams));
    delete body['oai_url'];
    delete body['oai_key'];
    const proxyUrl = getProxyUrl(container);
    const proxyParams = new URLSearchParams();
    proxyParams.append('ajax', 'true');
    proxyParams.append('_csrf', context.csrf);
    proxyParams.append('payload', JSON.stringify({
      url: oaiParams.oai_url,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${oaiParams.oai_key}`
      },
      body: body
    }));
    const response = await fetch(proxyUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: proxyParams
    });

    if (!response.ok) {
      throw new Error(t.requestFailed + ' (3)');
    }
    setOaiState(container, 1, t.receivingAnswer, null);

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';

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
            setOaiState(container, 2, t.requestFailed + ' (4)', null);
          }
        }
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(value, { stream: true });

      // Split buffer into Server-Sent Events
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
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (5)', null);
  }
}


async function sendOllamaRequest(container, oaiParams){
  const t = container.dataset;
  try {
    let body = JSON.parse(JSON.stringify(oaiParams));
    delete body['oai_url'];
    delete body['oai_key'];
    const proxyUrl = getProxyUrl(container);
    const proxyParams = new URLSearchParams();
    proxyParams.append('ajax', 'true');
    proxyParams.append('_csrf', context.csrf);
    proxyParams.append('payload', JSON.stringify({
      url: oaiParams.oai_url,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${oaiParams.oai_key}`
      },
      body: body
    }));
    const response = await fetch(proxyUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: proxyParams
    });

    if (!response.ok) {
      throw new Error(t.requestFailed + ' (6)');
    }
    setOaiState(container, 1, t.receivingAnswer, null);

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        setOaiState(container, 0, 'finish', null);
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      // Try to process complete JSON objects from the buffer
      let endIndex;
      while ((endIndex = buffer.indexOf('\n')) !== -1) {
        const jsonString = buffer.slice(0, endIndex).trim();
        try {
          if (jsonString) {
            const json = JSON.parse(jsonString);
            text += json.response
            setOaiState(container, 0, null, marked.parse(text));
          }
        } catch (e) {
          // If JSON parsing fails, output the error and keep the chunk for future attempts
          console.error('Error parsing JSON:', e, 'Chunk:', jsonString);
        }
        // Remove the processed part from the buffer
        buffer = buffer.slice(endIndex + 1); // +1 to remove the newline character
      }
    }
  } catch (error) {
    console.error(error);
    setOaiState(container, 2, t.requestFailed + ' (7)', null);
  }
}
