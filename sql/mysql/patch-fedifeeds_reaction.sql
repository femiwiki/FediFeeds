-- The fedifeeds_reaction table of sql/tables.json, for a wiki that has fedifeeds_item already
CREATE TABLE /*_*/fedifeeds_reaction (
  fr_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fr_feed VARBINARY(255) NOT NULL,
  fr_page INT UNSIGNED NOT NULL,
  fr_key VARBINARY(255) DEFAULT '' NOT NULL,
  fr_type VARBINARY(16) NOT NULL,
  fr_actor VARBINARY(255) NOT NULL,
  fr_activity VARBINARY(255) DEFAULT '' NOT NULL,
  fr_timestamp BINARY(14) NOT NULL,
  UNIQUE INDEX fr_feed_page_key_type_actor (
    fr_feed, fr_page, fr_key, fr_type,
    fr_actor
  ),
  INDEX fr_actor_activity (fr_actor, fr_activity),
  INDEX fr_page_type (fr_page, fr_type),
  PRIMARY KEY(fr_id)
) /*$wgDBTableOptions*/;
