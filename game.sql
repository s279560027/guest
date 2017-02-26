CREATE TABLE `game` (
  `id` int(11) NOT NULL,
  `lastping` int(11) NOT NULL,
  `closed` int(11) NOT NULL,
  `filled` int(11) NOT NULL,
  `human` tinyint(4) NOT NULL,
  `x_session` varchar(32) NOT NULL,
  `o_session` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `move` (
  `id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `type` varchar(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


ALTER TABLE `game`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `move`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `game_id` (`game_id`,`x`,`y`);
