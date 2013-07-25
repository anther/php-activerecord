<?php
require_once(__DIR__.'/RelationshipTest.php');
class ScopedRelationshipTest extends RelationshipTest
{
	
	public function set_up($connection_name=null)
	{
		parent::set_up();
		\ActiveRecord\Config::instance()->use_scoped_relationships(true);
	}
	
	public function test_has_many_build_association()
	{
		return true;
	}
	
	public function test_build_association_overwrites_guarded_foreign_keys()
	{
		return true;
	}
	
	public function test_has_many_with_sql_clause_options()
	{
		return true;
		Venue::$has_many[0] = array('events',
			'select' => 'type',
			'group'  => 'type',
			'limit'  => 2,
			'offset' => 1);
		Venue::first()->events;
		$this->assert_sql_has($this->conn->limit("SELECT type FROM events WHERE venue_id=? GROUP BY type",1,2),Event::table()->last_sql);
	}
	
	public function test_gh93_and_gh100_eager_loading_respects_association_options()
	{
		Venue::$has_many = array(array('events', 'class_name' => 'Event', 'order' => 'id asc', 'conditions' => array('length(title) = ?', 14)));
		$venues = Venue::find(array(2, 6), array('include' => 'events'));

		$this->assert_equals(1, count($venues[0]->events));
    }
	
	public function test_eager_loading_clones_related_objects()
	{
		//Actually DO want them to not be cloned
		$events = Event::find(array(2,3), array('include' => array('venue')));

		$venue = $events[0]->venue;
		$venue->name = "new name";

		$this->assert_equals($venue->id, $events[1]->venue->id);
		$this->assert_equals($venue->name, $events[1]->venue->name);
		$this->assert_equals(spl_object_hash($venue), spl_object_hash($events[1]->venue));
	}

	public function test_eager_loading_clones_nested_related_objects()
	{
		//Actually DO want them to not be cloned
		$venues = Venue::find(array(1,2,6,9), array('include' => array('events' => array('host'))));

		$unchanged_host = $venues[2]->events[0]->host;
		$changed_host = $venues[3]->events[0]->host;
		$changed_host->name = "changed";

		$this->assert_equals($changed_host->id, $unchanged_host->id);
		$this->assert_equals($changed_host->name, $unchanged_host->name);
		$this->assert_equals(spl_object_hash($changed_host), spl_object_hash($unchanged_host));
	}
	
}
