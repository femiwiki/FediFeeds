-- The fedifeeds_reaction table of sql/tables.json, for a wiki that has fedifeeds_item already
CREATE TABLE /*_*/fedifeeds_reaction (
  fr_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  fr_feed BLOB NOT NULL, fr_page INTEGER UNSIGNED NOT NULL,
  fr_key BLOB DEFAULT '' NOT NULL, fr_type BLOB NOT NULL,
  fr_actor BLOB NOT NULL, fr_activity BLOB DEFAULT '' NOT NULL,
  fr_timestamp BLOB NOT NULL
);

CREATE UNIQUE INDEX fr_feed_page_key_type_actor ON /*_*/fedifeeds_reaction (
  fr_feed, fr_page, fr_key, fr_type,
  fr_actor
);

CREATE INDEX fr_actor_activity ON /*_*/fedifeeds_reaction (fr_actor, fr_activity);

CREATE INDEX fr_page_type ON /*_*/fedifeeds_reaction (fr_page, fr_type);
