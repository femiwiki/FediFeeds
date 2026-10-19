-- The fedifeeds_item table of sql/tables.json, for a wiki that has fedifeeds_follower already
CREATE TABLE /*_*/fedifeeds_item (
  fi_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  fi_feed BLOB NOT NULL, fi_page INTEGER UNSIGNED NOT NULL,
  fi_timestamp BLOB NOT NULL
);

CREATE UNIQUE INDEX fi_feed_page ON /*_*/fedifeeds_item (fi_feed, fi_page);

CREATE INDEX fi_feed_timestamp ON /*_*/fedifeeds_item (fi_feed, fi_timestamp);
