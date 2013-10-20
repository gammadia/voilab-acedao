<?php

namespace Acedao;

class Query {

	/**
	 * @var Container
	 */
	private $container;

	public function __construct(Container $c) {
		$this->container = $c;
	}

	/**
	 * Méthode pour extraire l'alias du nom d'une table suivi de son alias...
	 *
	 * @param string $combined Une chaîne contenant le nom de la table et l'éventuel alias.
	 * @return array
	 */
	public function extractAlias($combined) {
		$t = explode(' ', $combined);
		$table = $t[0];
		$alias = $table;
		if (isset($t[1]))
			$alias = $t[1];

		return array(
			'table' => $table,
			'alias' => $alias
		);
	}

	/**
	 * Récupération des champs sélectionnés
	 *
	 * @param array $config
	 * @return array
	 */
	public function getSelectedFields($config) {
		$fields = isset($config['select']) ? $config['select'] : $this->container[$config['table']]->getDefaultFields();
		if (!in_array('id', $fields))
			array_unshift($fields, 'id');

		return $fields;
	}

	/**
	 * Ajout de l'alias aux champs sélectionnés
	 *
	 * @param string $alias
	 * @param array $select
	 * @return array
	 */
	public function aliaseSelectedFields($alias, $select) {
		$aliaseIt = function($field) use ($alias) {
			return $alias . '.' . $field;
		};

		return array_map($aliaseIt, $select);
	}

	/**
	 * Formattage d'un tableau de paramètres en s'assurant
	 * qu'ils sont PDO compliants (donc les clés doivent commencer par ":").
	 *
	 * @param array $params
	 * @return array
	 */
	public function formatQueryParamsKeys($params) {
		if (count($params) == 0)
			return $params;

		$keys = array_map(function($item) {
			if (substr($item, 0, 1) != ':')
				$item = ':' . $item;
			return $item;
		}, array_keys($params));
		return array_combine($keys, array_values($params));
	}

	/**
	 * Ajout d'alias de nom aux champs sélectionnés (le truc après le "as")
	 *
	 * @param array $fields
	 * @return array
	 */
	public function nameAliasesSelectedFields($fields) {
		return array_map(function($field) {
			$alias = str_replace('.', '__', $field);
			return $field . ' as ' . $alias;
		}, $fields);
	}

	/**
	 * Lance une requête SELECT sur la BD
	 *
	 * @param $config
	 * @return array
	 * @throws Exception
	 */
	public function select($config) {
		// S'il n'y a pas de table de départ, c'est mal parti pour faire un select...
		if (!isset($config['from']))
			throw new Exception('Array $parts needs a [from] entry.');

		// tentative de récupération de l'alias principal
		$config = array_merge($config, $this->extractAlias($config['from']));

		// récupération des champs sélectionnés et aliasement de chacun
		$select = $this->getSelectedFields($config);
		$select = $this->aliaseSelectedFields($config['alias'], $select);

		// initialisation du tableau de données de la query
		$data = array(
			'aliases' => array(),
			'flataliases' => array($config['table'] => $config['alias']),
			'base' => array(
				'table' => $config['table'],
				'alias' => $config['alias']
			),
			'parts' => array(
				'select' => $select,
				'from' => array($config['table'] . ' ' . $config['alias']),
				'leftjoin' => array(),
				'innerjoin' => array(),
				'where' => array(),
				'having' => array(),
				'groupby' => array(),
				'orderby' => array()
			),
			'params' => array()
		);

		// données supplémentaires
		if (isset($config['join'])) {
			foreach ($config['join'] as $filtername => $options) {
				$this->joinTable($data, $filtername, $options, $config);
			}
		}

		// réduction
		if (isset($config['where'])) {
			foreach ($config['where'] as $filtername => $options) {
				$this->applyConditions($data, $filtername, $options);
			}
		}

		// tri
		if (isset($config['orderby'])) {
			foreach ($config['orderby'] as $filtername => $options) {
				$this->applySorts($data, $filtername, $options);
			}
		}

		// au cas où des filtres auraient flanqué les mêmes parties de requêtes
		$parts = array_map('array_unique', $data['parts']);

		// formattage des paramètres, s'il y en a...
		$params = $this->formatQueryParamsKeys($data['params']);

		// ajout des alias aux champs sélectionnés
		$parts['select'] = $this->nameAliasesSelectedFields($parts['select']);

		// construction de la requête SQL
		$sql = sprintf('SELECT %1$s FROM %2$s', implode(', ', $parts['select']), implode(', ' , $parts['from']));
		if (count($parts['leftjoin']) > 0)
			$sql .= ' ' . implode(' ', $parts['leftjoin']);
		if (count($parts['innerjoin']) > 0)
			$sql .= ' INNER JOIN ' . implode(' ', $parts['innerjoin']);
		if (count($parts['where']) > 0)
			$sql .= ' WHERE ' . implode(' AND ', $parts['where']);
		if (count($parts['orderby']) > 0)
			$sql .= ' ORDER BY ' . implode(', ', $parts['orderby']);


//		echo $sql;
//		echo '<pre>';
//		print_r($params);
//		echo '</pre>';

		// récupération des résultats
		$results = getDatabase()->all($sql, $params);

		// regroupement des résultats
		$formatted = $this->hydrate($results, $config, $data['aliases']);

		return $formatted;
	}

	/**
	 * Formattage des résultats de la requêtes SQL
	 *
	 * @param array $results Resultset PDO
	 * @param array $config Configuration de la requête
	 * @param array $aliases Résumé des alias
	 * @return array
	 */
	public function hydrate($results, $config, $aliases) {
		$formatted = array();
		foreach ($results as $line) {
			$record = array();
			$relations = array();
			$path_exclude = array();
			foreach ($line as $fieldname => $value) {
				$t = explode('__', $fieldname);
				if ($t[0] == $config['alias']) {
					$record[$t[1]] = $value;
				} else {
					$path = $this->getPathAlias($aliases, $t[0]);
					$path_test = implode('_', $path);

					// si y a pas d'id, on considère que c'est un join sur rien...
					if ($t[1] == 'id' && $value == null || in_array($path_test, $path_exclude)) {
						$path_exclude[] = $path_test;
						continue;
					}

					$path[] = $t[1];
					$relations[] = array(
						'path' => $path,
						'value' => $value
					);
				}
			}

			foreach ($relations as $relation) {
				$value = array(
					array_pop($relation['path']) => $relation['value']
				);
				while ($key = array_pop($relation['path'])) {
					$value = array($key => $value);
				}

				// and there, the magic happens
				$record = array_merge_recursive($record, $value);
			}

			// gestion des relations 1-n
			$this->manageRelationsType($record, $config['table']);

			// fusion des records
			if (!isset($formatted[$record['id']])) {
				$formatted[$record['id']] = $record;
			} else {
				$this->fusionRecords($formatted[$record['id']], $record, null);
			}
		}

		return $formatted;
	}

	/**
	 * Inspection du nom du filtre pour savoir sur quelle table il s'applique
	 *
	 * @param $data
	 * @param $filtername
	 * @return array
	 * @throws Exception
	 */
	public function extractFilterAliasAndTable($data, $filtername) {
		$filter_array = explode('.', $filtername);
		$filtername = array_pop($filter_array);

		// si $filter_array n'est pas vide, c'est que le filtre a été appelé
		// sur un alias (probablement d'une autre table).
		if (count($filter_array) > 0) {
			$alias = array_pop($filter_array);
			if ($alias != $data['base']['alias']) {
				$path = $this->getPathAlias($data['aliases'], $alias);
				if (!$path) {
					throw new Exception(sprintf("Alias [%s] does not exist. Can't go on.", $alias));
				}
				$tablename = array_pop($path);
			} else {
				$tablename = $data['base']['table'];
			}

			// si $filter_array est vide, c'est qu'on appelle un filtre sur la table
			// de base de la requête.
		} else {
			$tablename = $data['base']['table'];
			$alias = $data['base']['alias'];
		}

		return  array($filtername, $tablename, $alias);
	}

	/**
	 * Récupération de la config du filtre
	 *
	 * @param Queriable $queriable
	 * @param string $type Le type de filtre (where, orderby)
	 * @param string $filtername Le nom du filtre
	 * @return bool|array
	 */
	public function retrieveFilter(Queriable $queriable, $type, $filtername) {
		$conditions = $queriable->getFilters($type);
		if (isset($conditions[$filtername])) {
			return $conditions[$filtername];
		}

		return false;
	}

	/**
	 * Applique les conditions (where) à la query
	 *
	 * @param array $data Les données de la query
	 * @param string $filtername Le nom du filtre
	 * @param array $options Les options envoyées pour ce filtre
	 * @throws Exception
	 */
	public function applyConditions(&$data, $filtername, $options) {
		// détermination de la table sur laquelle le filtre doit s'appliquer
		list($filtername, $tablename, $alias) = $this->extractFilterAliasAndTable($data, $filtername);

		// récupération du service
		$service = $this->container[$tablename];

		// récupération du filtre
		if (false === ($filter = $this->retrieveFilter($service, 'where', $filtername))) {
			throw new Exception(sprintf("Asked filter [%s] does not exist on table [%s]", $filtername, $tablename));
		}

		// remplacement des alias sur les conditions
		foreach ($filter as &$query_part) {
			$query_part = $this->aliaseIt($tablename, $alias, $query_part);
		}

		// création du SQL
		$where_str = implode(' AND ', $filter);
		$data['parts']['where'][] = $where_str;

		// traitement des paramètres
		$this->handleFilterParameters($data, $filtername, $options, $where_str);
	}

	/**
	 * Tentative de mapping entre les paramètres (:param1) de la query SQL et le tableau
	 * d'options fournis.
	 *
	 * @param $sql
	 * @param $options
	 * @return array|bool
	 */
	public function mapFilterParametersNames($sql, $options) {
		$result_preg = array();
		preg_match_all('/(\:\w+)/', $sql, $result_preg);
		$result_preg = array_unique($result_preg[0]);
		if (count($result_preg) > count($options)) {
			return false;
		}

		if (count($result_preg) > 0) {
			$options = array_values($options);
			if (count($options) > count($result_preg)) {
				$options = array_slice($options, 0, count($result_preg));
			}
			$result = array_combine($result_preg, $options);
		} else {
			$result = $options;
		}

		return $result;
	}

	/**
	 * Traitement des éventuels paramètres fournis avec le filtre
	 *
	 * @param $data
	 * @param $filtername
	 * @param $options
	 * @param $sql
	 * @throws Exception
	 */
	public function handleFilterParameters(&$data, $filtername, $options, $sql) {
		if (isset($options) && $options) {
			if (!is_array($options)) {
				$options = array($options);
			}
			$keys = array_keys($options);

			// Si les clés sont numériques, c'est qu'on n'a pas explicité le nom
			// des paramètres à passer au filtre. Donc le programme va essayer de
			// les mapper comme un grand en lisant la requête.
			if (is_int($keys[0])) {
				if (false === ($options = $this->mapFilterParametersNames($sql, $options))) {
					throw new Exception(sprintf("Filter [%s] does not provide enough values (%s) to feed the query parameters.", $filtername, count($keys)));
				}
			}

			$data['params'] = array_merge($data['params'], $options);
		}
	}

	/**
	 * Applique les tris (order by) à la query
	 *
	 * @param array $data Les données de la query
	 * @param string $filtername Le nom du filtre
	 * @param array $options Les options envoyées pour ce filtre
	 * @throws Exception
	 */
	public function applySorts(&$data, $filtername, $options) {
		list($filtername, $tablename, $alias) = $this->extractFilterAliasAndTable($data, $filtername);

		// récupération du service
		$service = $this->container[$tablename];

		// récupération du filtre
		if (false === ($filter = $this->retrieveFilter($service, 'orderby', $filtername))) {
			if ($this->container['config']['mode'] == 'strict') {
				throw new Exception(sprintf("Asked filter [%s] does not exist on table [%s]", $filtername, $tablename));
			}
			return;
		}

		// remplacement des alias sur les clause order by
		$table_names = array();
		$aliases_name = array();
		foreach ($data['flataliases'] as $tablename => $aliases) {
			if (!is_array($aliases)) {
				$aliases = array($aliases);
			}
			// s'il y a plusieurs alias pour la même table, on devrait avoir défini un mapping dans la config de la query.
			// sinon, c'est la merde -> exception.
			if (count($aliases) > 1) {
				if (isset($options['map']) && isset($options['map'][$tablename])) {
					$alias = $options['map'][$tablename];
				} else {
					throw new Exception(sprintf("The table [%s] as several aliases [%s]. You have to map the good one in the query call configuration.", $tablename, implode(',     ', $aliases)));
				}
			} else {
				$alias = $aliases[0];
			}
			$table_names[] = $tablename;
			$aliases_name[] = $alias;
		}
		foreach ($filter as &$query_part) {
			$query_part = $this->aliaseIt($table_names, $aliases_name, $query_part);
		}

		// création du SQL et gestion de la direction
		$orderby_str = implode(', ', $filter);
		$orderby_str = str_replace(':dir', $this->getSortDirection($options), $orderby_str);
		$data['parts']['orderby'][] = $orderby_str;
	}

	/**
	 * Récupération de la direction du tri
	 *
	 * @param $options
	 * @return string [asc, desc]
	 * @throws Exception
	 */
	public function getSortDirection($options) {
		if (!isset($options)) {
			return 'asc';
		}
		if (is_array($options)) {
			if (!isset($options['dir'])) {
				return 'asc';
			} else {
				return $options['dir'];
			}
		} else {
			if (in_array($options, array('asc', 'desc'))) {
				return $options;
			} else {
				if ($this->container['config']['mode'] == 'strict') {
					throw new Exception(sprintf("Provided options in 'orderby' filter [%s] is not recognized. Use 'asc' or 'desc'.", $options));
				} else {
					return 'asc';
				}
			}
		}
	}

	/**
	 * Fuionne 2 tableaux récursivement
	 *
	 * @param $one
	 * @param $two
	 */
	public function fusionRecords(&$one, $two) {
		foreach ($one as $fieldname => &$content) {
			if (!is_array($content)) continue;
			$newtwo = $two[$fieldname];

			if (!isset($content['id'])) {
				$content = array_merge($content, $newtwo);
				return;
			}

			$this->fusionRecords($content, $newtwo);
		}
	}

	/**
	 * Gestion des relations 1-n
	 *
	 * @param $record
	 * @param $base
	 */
	public function manageRelationsType(&$record, $base) {
		foreach ($record as $fieldname => &$content) {
			if (is_array($content)) {
				$this->manageRelationsType($content, $fieldname);
				$join_filter = $this->container[$base]->getFilters('join');
				if (isset($join_filter[$fieldname]['type']) && $join_filter[$fieldname]['type'] == 'many') {
					$content = array($content);
				}
			}
		}
	}

	public function aliaseIt($tableNames, $aliases, $queryPart) {
		if (!is_array($tableNames))
			$tableNames = array($tableNames);
		$tableNames = array_map(function ($item) { return '[' . $item . ']'; }, $tableNames);
		return str_replace($tableNames, $aliases, $queryPart);
	}

	/**
	 * Ajout d'une jointure à la query
	 *
	 * @param array $data Les données de la query
	 * @param string $filtername Le nom du filtre ([nom de table] [alias])
	 * @param array $options Les options envoyées pour ce join
	 * @param array $caller Les options envoyées pour la table qui appelle ce join
	 */
	public function joinTable(&$data, $filtername, $options, $caller) {
		$table_info = $this->extractAlias($filtername);
		$joined_table = $table_info['table'];
		$joined_alias = $table_info['alias'];
		$local_table = $caller['table'];
		$local_alias = $caller['alias'];

		// load DAO services
		$basetable_dao = $this->container[$local_table];
		$jointable_dao = $this->container[$joined_table];

		$basetable_joins = $basetable_dao->getFilters('join');
		$jointable_joins = $jointable_dao->getFilters('join');

		$default_options = $basetable_joins[$joined_table];

		// select
		if (isset($options['select'])) {
			$fields = $options['select'];
		} elseif (isset($default_options['select'])) {
			$fields = $default_options['select'];
		} else {
			$fields = $jointable_dao->getDefaultFields();
		}

		if (!in_array('id', $fields))
			array_unshift($fields, 'id');

		// On applique l'alias aux champs à sélectionner et on plante le tout
		// dans les query parts...
		$fields = $this->aliaseSelectedFields($joined_alias, $fields);
		$data['parts']['select'] = array_merge($data['parts']['select'], $fields);


		// leftjoin
		if (isset($default_options['on'])) {

			$filter_table = $joined_table;
			if ($joined_alias)
				$filter_table = $filter_table . ' ' . $joined_alias;

			// alias
			foreach ($default_options['on'] as &$query_part) {
				$query_part = $this->aliaseIt(
					array($joined_table, $local_table),
					array($joined_alias, $local_alias),
					$query_part
				);
			}

			// add sql code
			$leftjoin_str[] = sprintf('LEFT JOIN %s ON %s', $filter_table, implode(' AND ', $default_options['on']));
			$data['parts']['leftjoin'] = array_merge($data['parts']['leftjoin'], $leftjoin_str);
			$data['parts']['select'][] = $local_alias . '.id';
			$data['parts']['select'][] = $joined_alias . '.id';

			// handle aliases libraries
			$this->registerAlias($data['aliases'], $local_alias, $joined_alias, $joined_table, $basetable_joins, $jointable_joins);
			$this->addFlatAlias($data['flataliases'], $joined_table, $joined_alias);
		}


		/** ============== RECURSION ======================================== */

		// recherche de sous-filtres, soit directement dans les filtres, soit dans les options fournies par la requête de base
		$default_options['alias'] = $joined_alias;
		$default_options['table'] = $joined_table;

		if (isset($options['join'])) {
			foreach ($options['join'] as $subfilter_name => $subfilter_options) {
				$this->joinTable($data, $subfilter_name, $subfilter_options, $default_options);
			}
		}
		if (isset($default_options['join'])) {
			foreach ($default_options['join'] as $subfilter_name => $subfilter_options) {
				$this->joinTable($data, $subfilter_name, $subfilter_options, $default_options);
			}
		}

		/** ============ / RECURSION ======================================== */
	}

	/**
	 * Référencement d'un alias dans la bibliothèque d'alias de la query
	 *
	 * @param array $reference La bibliothèque d'alias, ou un sous-ensemble de celle-ci
	 * @param string $localAlias L'alias parent
	 * @param string $joinedAlias L'alias recherché
	 * @param string $joinedTable Le nom de la table à aliaser
	 * @param array $localTableFilters Les filtres 'join' de la table parente
	 * @param array $joinedTableFilters Les filtres 'join' de la table jointe
	 * @return bool
	 */
	public function registerAlias(&$reference, $localAlias, $joinedAlias, $joinedTable, $localTableFilters, $joinedTableFilters) {
		if (!isset($localTableFilters[$joinedTable])) {
			return false;
		}

		if (isset($reference[$localAlias])) {
			$reference[$localAlias]['children'][$joinedAlias] = array(
				'table' => $joinedTable,
				'type' => isset($joinedTableFilters[$joinedTable]['type']) ? $joinedTableFilters[$joinedTable]['type'] : 'one',
				'children' => array()
			);
		} else {
			$reference[$joinedAlias] = array(
				'table' => $joinedTable,
				'type' => isset($localTableFilters[$joinedTable]['type']) ? $localTableFilters[$joinedTable]['type'] : 'one',
				'children' => array()
			);
		}

		return true;
	}

	/**
	 * Stockage de la correspondance alias-table dans un simple tableau sans hiérarchie
	 *
	 * @param $reference
	 * @param $table
	 * @param $alias
	 */
	public function addFlatAlias(&$reference, $table, $alias) {
		$reference[$table][] = $alias;
	}

	/**
	 * Récupération du path vers un alias
	 *
	 * @param array $reference La bibliothèque des alias de la query (ou un sous-ensemble)
	 * @param string $alias L'alias à trouver
	 * @param bool $found Est-ce qu'on a trouvé l'alias
	 * @return array|false
	 */
	public function getPathAlias($reference, $alias, &$found = false) {
		$path = array();
		if (!isset($reference[$alias])) {
			foreach ($reference as $alias_name => $ref) {
				if ($alias == $alias_name)
					$found = true;
				if (count($ref['children']) > 0) {
					$path[] = $ref['table'];
					$subpath = $this->getPathAlias($ref['children'], $alias, $found);
					if (is_array($subpath))
						$path = array_merge($path, $subpath);
				}
			}
		} else {
			$path[] = $reference[$alias]['table'];
			$found = true;
		}

		if ($found)
			return $path;
		else
			return false;
	}

	final private function update($tableName, $data) {

		$sqlStmt = "UPDATE `". $tableName ."` SET ";

		$updates = array();

		foreach ($data as $key => $value) {
			if ($key !== 'id') {
				$updates[] = '`' .$key. '` = \'' . $value .'\'';
			}
		}
		$sqlStmt .= implode(', ', $updates) . ' WHERE `id` = ' .$data['id'];

		return getDatabase()->execute($sqlStmt);
	}

	final public function save($tableName, $data) {
		if (array_key_exists('id', $data)) {
			return $this->update($tableName, $data);
		}

		$sqlStmt = "INSERT INTO `". $tableName ."` ";

		$insertColumns = array();
		$insertValues = array();

		foreach ($data as $key => $value) {
			if ($key !== 'id') {
				$insertColumns[] = '`' .$key. '`';
				$insertValues[] = '\'' .$value. '\'';
			}
		}

		$sqlStmt .= '(' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')';

		return getDatabase()->execute($sqlStmt);
	}

	final public function delete($tablename, $id) {
		$sqlStmt = "DELETE FROM `" . $tablename . "` WHERE `id` = " .$id;
		return getDatabase()->execute($sqlStmt);
	}
}