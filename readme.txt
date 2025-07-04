=== OpenWebUI Chatbot ===
Contributors: yourname
Tags: chatbot, ai, openwebui, chat, artificial intelligence
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.2
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate OpenWebUI API chatbots into WordPress with full conversation memory, contact extraction, and Elementor support.

== Description ==

OpenWebUI Chatbot is a powerful WordPress plugin that integrates OpenWebUI API to provide AI-powered chat functionality on your website. The plugin offers comprehensive features including conversation memory, automatic contact information extraction, and seamless Elementor integration.

= Key Features =

* **Full Conversation Memory**: Chatbots remember previous messages in the same session
* **Contact Information Extraction**: Automatically captures names, emails, and phone numbers from conversations
* **Multiple Display Options**: Floating button, inline chat, popup button, and shortcode support
* **Elementor Integration**: Drag-and-drop widget for Elementor page builder
* **Admin Dashboard**: Complete management interface with statistics and chat history
* **Rate Limiting**: Built-in protection against spam and abuse
* **File Upload Support**: Users can upload documents for AI analysis
* **Export Functionality**: Export chat history and contacts to CSV
* **Privacy Compliant**: GDPR-ready with data export and deletion features

= Supported Display Types =

1. **Floating Chat Button**: Persistent chat icon in corner of website
2. **Inline Chat Widget**: Embedded chat interface directly in content
3. **Popup Button**: Custom button that opens chat in overlay
4. **Shortcode**: `[openwebui_chatbot]` for manual placement

= Contact Information Extraction =

The plugin automatically detects and saves:
* Names (when users say "My name is...", "I'm...", etc.)
* Email addresses (any valid email format)
* Phone numbers (various formats supported)

= Admin Features =

* Dashboard with conversation statistics
* Chatbot management (create, edit, delete)
* Chat history viewer with search and filtering
* Contact information management
* Export tools for data backup
* Connection testing and model selection

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/openwebui-chatbot/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to OpenWebUI > Settings to configure your API connection
4. Create your first chatbot in OpenWebUI > Chatbots
5. The chatbot will automatically appear on your site

= Configuration =

1. **API Setup**: Enter your OpenWebUI instance URL and API key
2. **Create Chatbot**: Set name, select AI model, configure system prompt
3. **Customize Appearance**: Upload avatar, set greeting message
4. **Display Options**: Choose how the chatbot appears on your site

== Frequently Asked Questions ==

= What is OpenWebUI? =

OpenWebUI is an open-source interface for running AI models locally or connecting to AI APIs. This plugin connects to your OpenWebUI instance to provide chat functionality.

= Do I need an OpenWebUI server? =

Yes, you need a running OpenWebUI instance with API access. You can set this up locally or use a hosted solution.

= Does the chatbot remember conversations? =

Yes! The plugin maintains conversation history within each session, so the AI remembers what was discussed earlier in the same conversation.

= Can I customize the chatbot appearance? =

Absolutely! You can customize colors, upload avatars, set greeting messages, and choose from multiple display types.

= Is it compatible with Elementor? =

Yes, the plugin includes a dedicated Elementor widget with full visual customization options.

= How does contact extraction work? =

The plugin uses pattern recognition to automatically detect names, emails, and phone numbers mentioned in conversations and saves them for later review.

= Is the plugin GDPR compliant? =

Yes, the plugin includes data export and deletion features to comply with privacy regulations.

== Screenshots ==

1. Floating chat button on website
2. Inline chat widget in action
3. Admin dashboard with statistics
4. Chatbot management interface
5. Contact information extraction
6. Elementor widget options
7. Chat history viewer
8. Settings configuration page

== Changelog ==

= 1.2.1 =
* Improved Elementor widget integration
* Minor bug fixes and stability improvements

= 1.1.0 =
* Added conversation memory functionality
* Implemented automatic contact information extraction
* Added Elementor widget support
* Enhanced admin dashboard with contact management
* Improved session management
* Added file upload support
* Better rate limiting and security
* Export functionality for contacts and chat history
* Privacy compliance features

= 1.0.0 =
* Initial release
* Basic chatbot functionality
* Admin interface
* Shortcode support
* File upload capability

== Upgrade Notice ==

= 1.2.1 =
Recommended update with minor fixes and improved Elementor support.

= 1.1.0 =
Major update with conversation memory, contact extraction, and Elementor support. Backup your site before upgrading.

== Support ==

For support and documentation, please visit [plugin website] or create an issue on GitHub.

== Privacy Policy ==

This plugin collects and stores:
* Chat messages and AI responses
* User information (if logged in) or IP addresses
* Automatically extracted contact information
* Session data for conversation continuity

All data can be exported or deleted upon request to comply with privacy regulations.
