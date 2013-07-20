<?php
namespace JDO;
class Relationships
{
	/**
	 * A model instance
	 */
	public $model;
	public $belongsTo = null;
	public $hasMany = null;
	public $hasOne = null;
	
	
	public function __construct($model)
	{
		$this->model = $model;
	}
	
	public static function executeEagerLoad($class,$models,$includes)
	{
		if(!$models)
		{
			return;
		}
		if(is_string($includes))
		{
			$includes = array($includes);
		}
		foreach($includes as $key=>$include)
		{
			if(!is_int($key))
			{
				$nextLevelIncludes = $include;
				if(is_string($nextLevelIncludes))
				{
					$nextLevelIncludes = array($nextLevelIncludes);
				}
				$include = $key;
			}
			else if(is_array($include))
			{
				$nextLevelIncludes = $include;
			}
			else
			{
				$nextLevelIncludes = null;
			}
			
			if(is_object($models))
			{
				$models = array($models);
			}
			foreach($models as $key=>$model)
			{
				$models[$key] = $model = ModelCacher::useCachedModel($model);
				$relationship = $model->hasRelationship($include);
				//$relationship->setEagerRelationship($eagerRelationship);
			}
			$eagerRelationship = new EagerRelationship($class,$models,$nextLevelIncludes);
			if(isset($relationship))
			{
				if(!$relationship)
				{
					throw new JDORelationshipNotDefinedException($class,$include);
				}
				$eagerRelationship->executeEagerLoad($include,$relationship);
			}
		}
		EagerRelationship::finishSessionIfComplete();
		
	}
	
	public function retrieveRelationship($name)
	{
		return $this->hasRelationship($name);
	}
	
	private $populatedRelationships = false;
	public function hasRelationship($name)
	{
		if(!$this->populatedRelationships)
		{
			$this->populatedRelationships = true;
			$this->populateBelongsTo();
			$this->populateHasMany();
			$this->populateHasOne();
		}
		if(isset($this->belongsTo[$name]))
		{
			return $this->belongsTo[$name];
		}
		if(isset($this->hasMany[$name]))
		{
			return $this->hasMany[$name];
		}
		if(isset($this->hasOne[$name]))
		{
			return $this->hasOne[$name];
		}
	}
	
	protected function populateBelongsTo()
	{
		if($this->belongsTo === null)
		{
			$relationArray = array();
			$model = $this->model;
			foreach($model::belongsTo() as $key=>$belongTo)
			{
				$relationArray[$key] = Relationship::createRelationship($this->model,$belongTo,Relationship::BELONGS_TO_ONE);
			}
			$this->belongsTo = $relationArray;
		}
	}
	
	protected function populateHasMany()
	{
		if($this->hasMany === null)
		{
			$relationArray = array();
			$model = $this->model;
			foreach($model::hasMany() as $key=>$hasMany)
			{
				$relationArray[$key] = Relationship::createRelationship($this->model,$hasMany,Relationship::HAS_MANY);
			}
			$this->hasMany = $relationArray;
		}
	}
	
	protected function populateHasOne()
	{
		if($this->hasOne === null)
		{
			$relationArray = array();
			$model = $this->model;
			foreach($model::hasOne() as $key=>$hasOne)
			{
				$relationArray[$key] = Relationship::createRelationship($this->model,$hasOne,Relationship::HAS_ONE);
			}
			$this->hasOne = $relationArray;
		}
	}
	
	/**
	 * Returns relevant relationship information
	 * If BelongsTo it returns the actual object it belongs to
	 * If HasMany it returns the collection
	 */
	public function getRelationship($name)
	{
		$relationship = $this->hasRelationship($name);
		if($relationship->hasOneResult())
		{
			$relationship->lazyLoadResult();
		}
		return $relationship;
	}
}

class Relationship implements \Countable, \IteratorAggregate, \ArrayAccess
{
	public $definedRelationship = null;
	public $type = null;
	public $model = null;
	protected $loadedRelationship = null;
	protected $eagerRelationship;
	
	public $scope;
	
	const BELONGS_TO_ONE = 1;
	const HAS_MANY = 2;
	const HAS_ONE = 3;
	
	public function __construct($baseModel,$definition,$type)
	{
		$this->model = $baseModel;
		$this->definedRelationship = $definition;
		$this->type = $type;
	}
	
	public function defineRelationship()
	{
		return $this->definedRelationship;
	}
	
	public static function createRelationship($baseModel,$definition,$type)
	{
		if(isset($definition['through']))
		{
			return new ThroughRelationship($baseModel,$definition,$type);
		}
		else
		{
			return new Relationship($baseModel,$definition,$type);
		}
		
	}
	
	public function setEagerRelationship($eager)
	{
		$this->eagerRelationship = $eager;
	}
	
	public function setRelationship($relationship)
	{
		$this->hasLoadedTheRelationship = true;
		if($this->hasOneResult() && is_array($relationship))
		{
			$this->loadedRelationship = reset($relationship);
		}
		else if(!$this->hasOneResult() && !is_array($relationship))
		{
			$this->loadedRelationship = array($relationship);
		}
		else
			$this->loadedRelationship = $relationship;
		return $this->loadedRelationship;
	}
	
	protected $_count = null;
	public function count()
	{
		if($this->_count === null)
		{
			$relationship = $this->lazyLoadRelationship();
			return count($relationship);
		}
		else
		{
			return $this->_count;
		}
	}
	
	public function hasOneResult()
	{
		return $this->type === self::BELONGS_TO_ONE || $this->type == self::HAS_ONE;
	}
	
	private $hasLoadedTheRelationship = false;
	public function lazyLoadRelationship()
	{
		if($this->hasLoadedTheRelationship)
		{
			return $this->loadedRelationship;
		}
		$scope = $this->establishScope();
		if($this->type === self::BELONGS_TO_ONE || $this->type === self::HAS_ONE)
		{
			return $this->setRelationship($scope->first());
		}
		else
		{
			return $this->setRelationship($scope->all());
		}
	}
	
	
	private $setUsableReturnValue = false;
	private $usableReturnValue;//array() is the 'Null' case for this variable
	/** If is a hasOneResult sort of relationship, will return either itself if the ONE exists, 
	 * or it will return NULL for no existance
	 * */
	public function getUsableReturnValue()
	{
		if($this->setUsableReturnValue)
		{
			return $this->usableReturnValue;
		}
		$this->setUsableReturnValue = true;
		if($this->hasLoadedTheRelationship)
		{
			return $this->usableReturnValue = $this->getUsableReturnValueFromLoadedRelationship();
		}
		if($this->returnsLoadedModels())
		{
			return $this->usableReturnValue = $this->lazyLoadRelationship();
		}
		$scope = $this->establishScope();
		$scope = clone $scope;
		if($scope->exists())
		{
			return $this->usableReturnValue = $this;
		}
		return $this->usableReturnValue = null;
	}
	
	protected function returnsLoadedModels()
	{
		return !isset($this->definedRelationship['asModels']) || 
			(isset($this->definedRelationship['asModels'])&& $this->definedRelationship['asModels'] == true);
	}
	
	protected function getUsableReturnValueFromLoadedRelationship()
	{
		if($this->loadedRelationship === null || (is_array($this->loadedRelationship) && count($this->loadedRelationship) === 0))
		{
			return $this->usableReturnValue = $this->loadedRelationship;
		}
		if($this->returnsLoadedModels())
		{
			return $this->usableReturnValue = $this->loadedRelationship;
		}
		return $this->usableReturnValue = $this;
	}
	
	public function model()
	{
		return $this->lazyLoadRelationship();
	}
	
	public function results()
	{
		return $this->model();
	}
	
	public function __get($name)
	{
		$model = $this->model();
		if(!$model)
		{
			throw new \JuggDataObjectException('Attempting to retrieve a value from an empty relationship');
		}
		return $model->{$name};
	}
	
	public function select($selectValues)
	{
		if(true || $this->type === self::HAS_ONE || $this->type === self::BELONGS_TO_ONE)
		{
			$this->scope = null; /** Force the scope to reload with new "Select" parameters */
			$this->definedRelationship['select'] = $selectValues;
			return $this;
		}
		return $this->__call('select',$selectValues);
	}
	
	protected function establishScope()
	{
		if($this->scope)
		{
			return $this->scope;
		}
		$definition = $this->definedRelationship;
		
		$class = $definition['className'];
		$model = $this->model;
		if(isset($definition['select']))
			$options['select'] = $definition['select'];
		if(isset($definition['order']))
			$options['order'] = $definition['order'];
		
		if($this->type === self::BELONGS_TO_ONE)
		{
			$options['conditions'] = array(
				$class::getPrimaryKeyField()=>$model->{$definition['foreignKey']}
			);
			return $this->scope = $class::scoped()->add_scope($options);
		}
		if($this->type === self::HAS_MANY || $this->type === self::HAS_ONE)
		{
			if(!isset($definition['foreignKey']))
			{
				throw new JDORelationshipException('Foreign Key not defined for this relationship');
			}
			$options['conditions'] = array(
				$definition['foreignKey']=>$this->model->{$model::getPrimaryKeyField()}
			);
			return $this->scope = $class::scoped()->add_scope($options);
		}
		throw new \Exception('Could not establish a scope for this type of relationship: '.$this->type);
	}

	public function __call($method,$args)
	{
		if(!$this->scope)
			$this->establishScope();
		if($this->scope)
		{
			$scope = clone $this->scope;
			return call_user_func_array(array($scope,$method), $args);
		}
		else
		{
			throw new \JuggDataObjectException('Could not call a method on this relationship');
		}
	}

	public function asArray()
	{
		$relationship = $this->lazyLoadRelationship();
		return $relationship;
	}
	
	public function getIterator()
    {
        return new \ArrayIterator($this->lazyLoadRelationship());
    }
	
	public function offsetExists( $offset )
	{
		$relationship = $this->lazyLoadRelationship();
		return isset($relationship[$offset]);
	}
	public function offsetGet( $offset )
	{
		$relationship = $this->lazyLoadRelationship();
		return $relationship[$offset];
	}
	public function offsetSet ( $offset , $value )
	{
		throw new \JuggDataObjectException('Cannot manually set relationship');
	}
	public function offsetUnset ( $offset )
	{
		throw new \JuggDataObjectException('Cannot unset a relationship');
	}
}

class ThroughRelationship extends Relationship
{
	
	public $referencedRelationship;
	public function establishScope()
	{
		$this->lazyLoadRelationship();
	}
	
	protected $hasDefinedRelationship = false;
	public function defineRelationship()
	{
		if($this->hasDefinedRelationship)
		{
			return $this->definedRelationship;
		}
		else
		{
			$this->hasDefinedRelationship = true;
			$this->referencedRelationship = $this->model->hasRelationship($this->definedRelationship['through']);
			return $this->definedRelationship = $this->definedRelationship + $this->referencedRelationship->definedRelationship;
		}
	}

	public function getUsableReturnValue()
	{
		$this->lazyLoadRelationship();
		return $this->getUsableReturnValueFromLoadedRelationship();
	}
	
	public function lazyLoadRelationship()
	{
		if($this->loadedRelationship !== null)
		{
			return $this->loadedRelationship;
		}
		
		if(!isset($this->definedRelationship['to']))
		{
			//Guess the to to be the same as the through
			$this->definedRelationship['to'] = $this->definedRelationship['through'];
		}
		
		$definition = $this->definedRelationship;
		$parentRelationship = $definition['through'];
		
		$relationship = $this->model->hasRelationship($definition['through']);
		$models = $relationship->lazyLoadRelationship();
		if(!is_array($models))
		{
			$models = array($models);
		}
		if($models)
		{
			$throughRelationship = $models[0]->hasRelationship($definition['to']);
			if(!$throughRelationship)
			{
				throw new JDORelationshipNotDefinedException($models[0],$definition['to']);
			}
			$throughDefinition = $throughRelationship->definedRelationship;
			$throughClass = $throughDefinition['className'];
			$eager = new EagerRelationship(get_class($models[0]),$models,null);
			$eager->executeEagerLoad($definition['to'],$throughRelationship);
			$relations = array_values($eager->loadedRelationship);
			$this->scope = $eager->scope;
			return $this->setRelationship($relations);
			/** Need to set this loadedRelationship **/
		}
		
	}
}

class EagerRelationship extends Relationship
{
	public $class;
	public $includes;
	public $models;
	
	public static $eagerRelationships = array();
	
	protected $completedSession = false;
	protected $results;
	
	public function __construct($class,$models,$includes)
	{
		$this->class = $class;
		$this->models = $models;
		$this->includes = $includes;
		static::$eagerRelationships[] = $this;
	}
	
	public static function finishSessionIfComplete()
	{
		foreach(static::$eagerRelationships as $eager)
		{
			if(!$eager->completedSession)
				return false;
		}
		ModelCacher::$cachedModels = array();
		static::$eagerRelationships = array();
		return true;
	}
	
	public function executeEagerLoad($relationName,$sampleRelationship)
	{
		$class = $this->class;
		$definedRelationship = $sampleRelationship->defineRelationship();
		
		if($sampleRelationship instanceof ThroughRelationship)
		{
			$this->completedSession = true;
			return $sampleRelationship->lazyLoadRelationship();
		}
		
		if(isset($definedRelationship['order']))
			$options['order'] = $definedRelationship['order'];
		if(isset($definedRelationship['select']))
			$options['select'] = $definedRelationship['select'];
		
		if($sampleRelationship->type == self::HAS_MANY || $sampleRelationship->type == self::HAS_ONE)
		{
			$keys = \Arrays::list_elements_by_a_property($this->models, $class::getPrimaryKeyField());
			$keys = array_keys($keys);
			
			$options['conditions'] = array($definedRelationship['foreignKey'].' IN (?)',$keys);
			$options['include'] = $this->includes;
			$this->scope = $definedRelationship['className']::scoped()
				->add_scope($options);
			$results = $this->scope->all();
			foreach($results as $key=>$result)
			{
				$foreignValue = $result->{$definedRelationship['foreignKey']};
				$this->results[$foreignValue][] = ModelCacher::useCachedModel($result);
			}
			//$this->results = \Arrays::group_by($results, $definedRelationship['foreignKey']);
			
			$primaryKeyField = $definedRelationship['className']::getPrimaryKeyField();
			foreach($this->models as $key=>$model)
			{
				$relationship = $model->hasRelationship($relationName);
				$primaryKey = $model->{$primaryKeyField};
				if(isset($this->results[$primaryKey]))
					$relationship->setRelationship($this->results[$primaryKey]);
				else
					$relationship->setRelationship(null);
			}
		}
		if($sampleRelationship->type == self::BELONGS_TO_ONE)
		{
			$keys = \Arrays::list_elements_by_a_property($this->models, $definedRelationship['foreignKey']);
			$keys = array_keys($keys);
			
			$options['conditions'] = array($definedRelationship['className']::getPrimaryKeyField().' IN (?)',$keys);
			$options['include'] = $this->includes;
			
			$this->scope = $definedRelationship['className']::scoped()
				->add_scope($options);
				
			$results = $this->scope->all();
			$primaryKeyField = $definedRelationship['className']::getPrimaryKeyField();
			foreach($results as $key=>$result)
			{
				//$results[$key] = ModelCacher::useCachedModel($result);
				$this->results[$result->{$primaryKeyField}] = ModelCacher::useCachedModel($result);
			}
			//$this->results = \Arrays::list_elements_by_a_property($results, $definedRelationship['className']::getPrimaryKeyField());
			foreach($this->models as $key=>$model)
			{
				$relationship = $model->hasRelationship($relationName);
				$foreignKey = $model->{$definedRelationship['foreignKey']};
				if(isset($this->results[$foreignKey]))
					$relationship->setRelationship($this->results[$foreignKey]);
				else
					$relationship->setRelationship(null);
			}
		}
		$this->loadedRelationship = $this->results;
		$this->completedSession = true;
	}
}

class ModelCacher
{
	public static $cachedModels = array();
	public static function useCachedModel($model)
	{
		if(isset(static::$cachedModels[get_class($model)][$model->id]))
		{
			return static::$cachedModels[get_class($model)][$model->id];
		}
		else
		{
			return static::$cachedModels[get_class($model)][$model->id] = $model;
		}
	}
}
class JuggDataObjectException extends Exception{}
class JDORelationshipException extends JuggDataObjectException{}
class JDORelationshipNotDefinedException extends JDORelationshipException{
	
	protected $class, $relationshipName;
		
	public function __construct($class,$relationshipName)
	{
		if(is_object($class))
		{
			$class = get_class($class);
		}
		$this->class = $class;
		$this->relationshipName = $relationshipName;
		parent::__construct($this->makeMessage());
	}
	
	protected function makeMessage()
	{
		return "Relationship \"{$this->relationshipName}\" is not defined for ".$this->class;
	}
}
