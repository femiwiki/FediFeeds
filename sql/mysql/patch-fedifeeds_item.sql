-- The fedifeeds_item table of sql/tables.json, for a wiki that has fedifeeds_follower already
CREATE TABLE /*_*/fedifeeds_item (
  fi_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fi_feed VARBINARY(255) NOT NULL,
  fi_page INT UNSIGNED NOT NULL,
  fi_timestamp BINARY(14) NOT NULL,
  UNIQUE INDEX fi_feed_page (fi_feed, fi_page),
  INDEX fi_feed_timestamp (fi_feed, fi_timestamp),
  PRIMARY KEY(fi_id)
) /*$wgDBTableOptions*/;
