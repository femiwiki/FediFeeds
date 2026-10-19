-- The fedifeeds_quote table of sql/tables.json, for a wiki that has fedifeeds_item already
CREATE TABLE /*_*/fedifeeds_quote (
  fq_id INT UNSIGNED AUTO_INCREMENT NOT NULL,
  fq_feed VARBINARY(255) NOT NULL,
  fq_item BLOB NOT NULL,
  fq_actor VARBINARY(255) NOT NULL,
  fq_post VARBINARY(255) NOT NULL,
  fq_request VARBINARY(255) NOT NULL,
  fq_timestamp BINARY(14) NOT NULL,
  UNIQUE INDEX fq_post_feed (fq_post, fq_feed),
  INDEX fq_actor_request (fq_actor, fq_request),
  PRIMARY KEY(fq_id)
) /*$wgDBTableOptions*/;
