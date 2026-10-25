# Setting up Discord

Discord is optional. Posts › Undergrowth does everything Discord does. With Discord set up, each new link suggestion is also posted to a private channel in your own server, with Accept and Veto buttons. A veto from Discord is permanent. The Undo on Posts › Undergrowth is only in the notice right after a veto made there. Your site must be reachable from the internet over HTTPS, because Discord sends each button click to it.

1. In the Discord developer portal, create a new application just for Undergrowth. Do not reuse an existing bot: once an application has an Interactions Endpoint URL, Discord sends its interactions to that address instead of over the gateway, which can stop an existing bot from working.
2. Copy the Application ID and the Public Key from its General Information page into Settings › Undergrowth.
3. On the Bot page, reset the token and paste it into Bot token. Undergrowth never shows it again.
4. Invite the bot to your server with the scope `bot` and the permissions View Channel and Send Messages, and give it access to a private channel. Anyone who can read the channel sees your suggestions.
5. In Discord, turn on Developer Mode (User Settings › Advanced), right-click the channel, choose Copy Channel ID, and paste it into Channel ID for link suggestions. Save the settings.
6. Copy the Interactions endpoint URL shown on the settings page into Interactions Endpoint URL on the application's General Information page, and save it there. Discord checks the address before it accepts it.
7. An administrator enters the Discord user ID of each person who may press the buttons: on Users › Profile for their own account, or on Users › All Users › Edit for anyone else. A click works only for a linked Discord user whose WordPress account can edit the post; anyone else gets a private refusal and nothing changes.

Each message sends Discord the titles of both posts, the suggested sentence, which is your own words, and links to both posts. Only suggestions made after you set the channel are posted.
