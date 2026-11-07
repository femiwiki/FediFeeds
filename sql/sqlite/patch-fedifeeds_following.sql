-- The fedifeeds_following table of sql/tables.json, for a wiki that has fedifeeds_actor already
CREATE TABLE /*_*/fedifeeds_following (
  fg_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  fg_feed BLOB NOT NULL, fg_account BLOB NOT NULL,
  fg_timestamp BLOB NOT NULL
);

CREATE UNIQUE INDEX fg_feed_account ON /*_*/fedifeeds_following (fg_feed, fg_account);
