-- The fedifeeds_follower table of sql/tables.json, for a wiki that has fedifeeds_key already
CREATE TABLE /*_*/fedifeeds_follower (
  ff_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  ff_feed BLOB NOT NULL, ff_actor BLOB NOT NULL,
  ff_inbox BLOB NOT NULL, ff_timestamp BLOB NOT NULL
);

CREATE UNIQUE INDEX ff_feed_actor ON /*_*/fedifeeds_follower (ff_feed, ff_actor);

CREATE INDEX ff_actor ON /*_*/fedifeeds_follower (ff_actor);
