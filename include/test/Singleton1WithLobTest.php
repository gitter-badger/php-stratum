<?php
//----------------------------------------------------------------------------------------------------------------------
class Singleton1WithLobTest extends PHPUnit_Framework_TestCase
{
  //--------------------------------------------------------------------------------------------------------------------
  /** Setups a form with a select form control.
   */
  protected function setUp()
  {
    TST_DL::Connect( 'localhost', 'test', 'test', 'test' );
  }

  //--------------------------------------------------------------------------------------------------------------------
  /** Stored routine with designation type singleton1 must return 1 value and 1 value only.
   */
  public function test1()
  {
    $ret = TST_DL::TestSingleton1aWithLob( 1, 'blob' );
    $this->assertEquals( '1', $ret );
  }

  //--------------------------------------------------------------------------------------------------------------------
  /** An exception must be thrown when a stored routine with designation type singleton1 returns 0 values.
   *  @expectedException Exception
   */
  public function test2()
  {
    TST_DL::TestSingleton1aWithLob( 0, 'blob' );
  }

  //--------------------------------------------------------------------------------------------------------------------
  /** An exception must be thrown when a stored routine with designation type singleton1 returns more than 1 values.
   *  @expectedException Exception
   */
  public function test3()
  {
    TST_DL::TestSingleton1aWithLob( 2, 'blob' );
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
