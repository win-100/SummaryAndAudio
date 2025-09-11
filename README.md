# FreshRSS Article Summary Extension

This project is a fork of [LiangWei88/xExtension-ArticleSummary](https://github.com/LiangWei88/xExtension-ArticleSummary), updated to better integrate with FreshRSS and to leverage the latest OpenAI API models. In addition to the original summarization capabilities, this version allows users to generate two types of summaries: a concise short summary and a more detailed long summary.

## Features
- **Forked and Modernized**: Originates from the LiangWei88 project but refactored for smoother integration and maintenance within FreshRSS.
- **Latest OpenAI Model**: Uses the most recent OpenAI model by default for higher quality summaries.
- **Two Summary Lengths**: Users can request either a short summary for quick insight or a long summary for deeper understanding.
- **API Configuration**: Configure the base URL, API key, model name, and prompt through a simple form.
- **Summarize Button**: Adds a "summarize" button to each article, allowing users to generate a summary with a single click.
- **Markdown Support**: Converts HTML content to Markdown before sending it to the API.
- **Text-to-Speech**: Listen to articles using OpenAI's TTS with adjustable reading speed. Audio playback always uses OpenAI regardless of the summary provider.
- **Server-side Requests**: Summary generation requests are now performed on the server, avoiding browser CSP restrictions and keeping API keys hidden from the client.
- **Error Handling**: Provides feedback in case of API errors or incomplete configurations.
- **Smart Fallback**: Uses the article's description if the main content is empty or contains only images.

## Installation

1. **Download the Extension**: Clone or download this repository to your FreshRSS extensions directory.
2. **Enable the Extension**: Go to the FreshRSS extensions management page and enable the "ArticleSummary" extension.
3. **Configure the Extension**: Navigate to the extension's configuration page to set up your API details.

## Configuration

### Summary
These settings control how article summaries are generated. You may choose the provider for text summarisation and supply separate prompts for the two available levels of detail.

1. **Provider**: Select the service used to generate summaries ("OpenAI" or "Ollama").
2. **Base URL**: Enter the base URL of your language model API (e.g., `https://api.openai.com/`). Do not include the version path (e.g., `/v1`).
3. **API Key**: Provide your API key for authentication.
4. **Model Name**: Specify the model name you wish to use for summarisation (e.g., `gpt-4.1`).
5. **Prompt (High-Level)**: Prompt used to produce a concise, high-level summary.
6. **Second Prompt (Detailed)**: Prompt used when requesting an additional, more detailed summary.

### Audio (OpenAI only)
Audio playback always uses the OpenAI Text-to-Speech API, regardless of the provider selected for summaries.

1. **Voice & TTS Model**: Choose the OpenAI voice and TTS model used for audio playback.
2. **Reading Speed**: Set the playback speed between `0.5` and `4` (default `1.1`).

## Usage

Once configured, the extension will automatically add a "summarize" button to each article. Clicking the button will:

1. Send the article content to the configured API.
2. Display both the short and long summaries below the button.

## Dependencies

- **Axios**: Used for making HTTP requests from the browser.
- **Marked**: Converts Markdown content to HTML for display.

## Contributing

Contributions are welcome! Please feel free to submit issues or pull requests.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Acknowledgments

- Original project by [LiangWei88](https://github.com/LiangWei88/xExtension-ArticleSummary).
- Thanks to the FreshRSS community for providing a robust platform for RSS management.

## History
- Version: 0.1.1 (2024-11-20)
  > **Bug Fix**: Prevented the summary button from affecting the title list display. Previously, the `entry_before_display` hook was causing the summary button to appear in the title list, leading to display issues. Now, the button initially has no text and adds text only when the article is displayed.

---
For any questions or support, please open an issue on this repository.
