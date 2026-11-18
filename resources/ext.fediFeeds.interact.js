/**
 * Makes the links on action=info ask for the reader's fediverse server and send them to its
 * /authorize_interaction, which Mastodon and Misskey both have, to open the page's note there.
 * The server's WebFinger is not asked for its own templates, as the wiki's Content Security
 * Policy keeps scripts from fetching other hosts.
 */
const STORAGE_KEY = "fedifeeds-interact-server";

/**
 * The host name that the reader's input names: a handle such as @alice@example.org, a URL on
 * the server, or the host name itself.
 *
 * @param {string} input
 * @return {string|null}
 */
function getHost(input) {
	let host = input.trim();
	if (/^https?:\/\//i.test(host)) {
		try {
			host = new URL(host).host;
		} catch (_e) {
			return null;
		}
	} else {
		host = host.slice(host.lastIndexOf("@") + 1).replace(/\/+$/, "");
	}
	// A port, a path or a user part is not a server the reader named
	if (!host || /[\s:/?#@\\]/.test(host)) {
		return null;
	}
	try {
		// The setter leaves a host name it can not take as it was
		const url = new URL("https://invalid/");
		url.hostname = host;
		return url.hostname !== "invalid" && url.hostname.includes(".")
			? url.hostname
			: null;
	} catch (_e) {
		return null;
	}
}

/**
 * @param {string} noteId
 * @param {string} value What the reader typed last
 */
function ask(noteId, value) {
	OO.ui
		.prompt(mw.msg("fedifeeds-interact-prompt"), {
			textInput: {
				placeholder: mw.msg("fedifeeds-interact-placeholder"),
				value: value,
			},
		})
		.then((input) => {
			if (input === null) {
				return;
			}
			const host = getHost(input);
			if (host === null) {
				OO.ui
					.alert(mw.msg("fedifeeds-interact-invalid"))
					.then(() => ask(noteId, input));
				return;
			}
			mw.storage.set(STORAGE_KEY, input.trim());
			location.href = `https://${host}/authorize_interaction?uri=${encodeURIComponent(noteId)}`;
		});
}

$(document).on("click", ".ext-fedifeeds-interact", function (e) {
	e.preventDefault();
	ask(this.dataset.note, mw.storage.get(STORAGE_KEY) || "");
});

module.exports = { getHost };
