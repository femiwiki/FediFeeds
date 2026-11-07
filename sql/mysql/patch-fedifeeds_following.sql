-- The fedifeeds_following table of sql/tables.json, for a wiki that has fedifeeds_actor already
CREATE TABLE /*_*/fedifeeds_following (
  fg_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fg_feed VARBINARY(255) NOT NULL,
  fg_account VARBINARY(255) NOT NULL,
  fg_timestamp BINARY(14) NOT NULL,
  UNIQUE INDEX fg_feed_account (fg_feed, fg_account),
  PRIMARY KEY(fg_id)
) /*$wgDBTableOptions*/;
