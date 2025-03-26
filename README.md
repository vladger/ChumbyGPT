Basic HTTP GET flash widget for GPT access or text data sending on Chumby devices, with Cyrillic symbols support. Requires HTTP server with crossdomain.xml in www root, or web proxy server with crossdomain.xml in www root to bypass crossdomain.

Default FLA Contains basic GPT public server text.pollinations.ai, requires crossdomain proxy to send requests to it (set up the proxy yourself).
Does not save conversation history.

By default, once the widget is loaded, left and right screen parts are not working on Dash for some reason, press Snooze/Menu button, tap Apps icon on bottom bar, and tap the darkened part of the screen (outside apps selection popup) to fix the touch zone issue (do it each time you open the widget).

Requirements: any web server with PHP support, Adobe Flash Professional CS6 or higher


Setup guide:
1) Put the proxy.php file on the root of your web server, make sure PHP scripts are enabled
2) Open the .fla file in FlashPro, modify the ActionScript in first frame, scroll to bottom? find string url, replace to "url = "http://YOURSERVERIP/proxy.php?url=http%3A%2F%2Ftext.pollinations.ai/" + escape(inputText);", replace YOURSERVER to your server ip
3) Compile the SWF (Ctrl+Enter) and upload it on chumby.com cloud (thumbnail example.jpg), add to any of channels
4) Select the channel and widget on Chumby/Dash, when widget loads up, press Snooze/Menu button, tap Apps icon on bottom bar, and tap the darkened part of the screen (outside apps selection popup). App Selection popup should close and the touch fully accessible.
