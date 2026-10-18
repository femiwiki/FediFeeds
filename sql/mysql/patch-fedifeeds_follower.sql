-- The fedifeeds_follower table of sql/tables.json, for a wiki that has fedifeeds_key already
CREATE TABLE /*_*/fedifeeds_follower (
  ff_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  ff_feed VARBINARY(255) NOT NULL,
  ff_actor VARBINARY(255) NOT NULL,
  ff_inbox BLOB NOT NULL,
  ff_timestamp BINARY(14) NOT NULL,
  UNIQUE INDEX ff_feed_actor (ff_feed, ff_actor),
  INDEX ff_actor (ff_actor),
  PRIMARY KEY(ff_id)
) /*$wgDBTableOptions*/;
