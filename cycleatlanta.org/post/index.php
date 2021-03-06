<?php

require_once('Util.php');
require_once('UserFactory.php');
require_once('TripFactory.php');
require_once('CoordFactory.php');
require_once('NoteFactory.php');
require_once('Decompress.php');

define( 'DATE_FORMAT',        'Y-m-d h:i:s' );
define( 'PROTOCOL_VERSION_1', 1 );
define( 'PROTOCOL_VERSION_2', 2 );
define( 'PROTOCOL_VERSION_3', 3 ); // this is for uploading the trip data (compressed)
define( 'PROTOCOL_VERSION_4', 4 ); // this is for uploading the note data (compressed)

Util::log( " ");
Util::log( "+++++++++++++ Production: Upload Start +++++++++++++");

/*
Util::log ( "++++ HTTP Headers ++++" );
$headers = array();
foreach($_SERVER as $key => $value) {
    if (substr($key, 0, 5) <> 'HTTP_') {
        continue;
    }
    $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
    
    Util::log ( "{$header}: {$value}" );
    //$headers[$header] = $value;
}

Util::log ( "++++++++++++++++++++++" );   
*/

// take protocol from HTTP header if present; otherwise URL query var or POST body
if (isset($_SERVER['HTTP_CYCLEATL_PROTOCOL_VERSION'])) {
  $version = intval($_SERVER['HTTP_CYCLEATL_PROTOCOL_VERSION']);
} elseif (isset($_GET['version'])) {
  $version = intval($_GET['version']);
} else {
  $version = intval($_POST['version']);
}

Util::log ( "INFO Protocol version {$version}");

// older protocol types use a urlencoded form body
if ( $version == PROTOCOL_VERSION_1 || $version == PROTOCOL_VERSION_2 || $version == null) {
  $coords   = isset( $_POST['coords'] )  ? $_POST['coords']  : null; 
  $device   = isset( $_POST['device'] )  ? $_POST['device']  : null; 
  $notes    = isset( $_POST['notes'] )   ? $_POST['notes']   : null; 
  $purpose  = isset( $_POST['purpose'] ) ? $_POST['purpose'] : null; 
  $start    = isset( $_POST['start'] )   ? $_POST['start']   : null; 
  $userData = isset( $_POST['user'] )    ? $_POST['user']    : null; 
} 

// new zipped body, still mostly urlencoded form
elseif ( $version == PROTOCOL_VERSION_3) {
  if ($_SERVER['HTTP_CONTENT_ENCODING'] == 'gzip' ||
      $_SERVER['HTTP_CONTENT_ENCODING'] == 'zlib') {
    $body = decompress_zlib($HTTP_RAW_POST_DATA);
  } else {
    $body = $HTTP_RAW_POST_DATA;
  }
  $query_vars = array();
  parse_str($body, $query_vars);
  $coords   = isset( $query_vars['coords'] )  ? $query_vars['coords']  : null;
  $device   = isset( $query_vars['device'] )  ? $query_vars['device']  : null;
  $notes    = isset( $query_vars['notes'] )   ? $query_vars['notes']   : null;
  $purpose  = isset( $query_vars['purpose'] ) ? $query_vars['purpose'] : null;
  $start    = isset( $query_vars['start'] )   ? $query_vars['start']   : null;
  $userData = isset( $query_vars['user'] )    ? $query_vars['user']    : null;
}
elseif ( $version == PROTOCOL_VERSION_4 ) {
  $note   		= isset( $_POST['note'] )  			? $_POST['note']  			: null;
  $device   	= isset( $_POST['device'] )  		? $_POST['device']  		: null;
  $imageData 	= isset( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] 	: null;

}

// validate device ID: should be 32 but some android devices are reporting 31
// TODO: This will need to change once iOS7 device ID bug is fixed and released
if ( is_string( $device ) && strlen( $device ) === 32 || strlen( $device ) === 31)
{
	// HOT FIX: check if the deviceID is the problematic one from iOS7, if so, append the email address if it exists, if no email, append random hash (creating a new user).
	$userData = (object) json_decode( $userData );
	if ( $device == "0f607264fc6318a92b9e13c65db7cd3c" ){
		Util::log( "WARNING: iOS7 generic device id!");
		if ($userData->email) {
			$device .= trim($userData->email);
			Util::log ( "INFO: New deviceID: {$device}" );	
		}
		if ($userData->app_version == NULL) {
			$userData->app_version = "1.0 on iOS 7";
		} 	
	}
	
	// try to lookup user by this device ID
	$user = null;
	if ( $user = UserFactory::getUserByDevice( $device ) )
	{
		Util::log( "INFO found user {$user->id} for device $device" );
		//print_r( $user );
	}
	elseif ( $user = UserFactory::insert( $device ) )
	{
		// nothing to do
	}

	if ( $user )
	{
		// add a note
		if ( $version == PROTOCOL_VERSION_4 ) {
			//only one note sent at a time
			$noteObj = json_decode( $note );
			
			// get the first coord's start timestamp if needed
			if ( !$timeStamp )
				$timeStamp = $noteObj->r;
			
			// first check for existing note
			if ( $note_id = NoteFactory::getNoteByUserStart( $user->id, $timeStamp ) ){
				// we've already saved a trip for this user with this start time
				Util::log( "WARNING a note for user {$user->id} at {$timeStamp} has already been saved" );
				Util::log( "+++++++++++++ Production: Upload Finished ++++++++++");
				//
				// add code here to handle updating the note details if implemented 
				//
				
				header("HTTP/1.1 202 Accepted");
				$response = new stdClass;
				$response->status = 'success';
				echo json_encode( $response );
				exit;
			}
			else {
				Util::log( "Saving a new note for user {$user->id} at {$timeStamp}" );	
				// create a new note, 
				if ( $addedNote = NoteFactory::insert(	$user->id,
										$noteObj->r, //recorded timestamp
										$noteObj->l, //latitude
										$noteObj->n, //longitude
										$noteObj->a, //altitude
										$noteObj->s, //speed
										$noteObj->h, //haccuracy
										$noteObj->v, //vaccuracy
										$noteObj->t, //note type
										$noteObj->d, //note details
										$noteObj->i, //image url (name only)
										$imageData ) )
				{
					Util::log( "Added note {$addedNote->id} type {$addedNote->note_type}" );
				} else
					Util::log( "WARNING failed to add note {$addedNote->id} type {$addedNote->note_type}" );					
			}
			Util::log( "+++++++++++++ Production: Upload Finished ++++++++++");
			header("HTTP/1.1 201 Created");
			$response = new stdClass;
			$response->status = 'success';
			echo json_encode( $response );
			exit;
		} 
		// add a trip
		else { 		
			// check for userData and update if needed
			if ( $userObj  = new User( $userData ) )
			{
				// update user record
				if ( $tempUser = UserFactory::update( $user, $userObj ) )
					$user = $tempUser;
			}
	
			$coords  = (array) json_decode( $coords );
			$n_coord = count( $coords );
			//Util::log( "n_coord: {$n_coord}" );
	
			// sort incoming coords by recorded timestamp
			// NOTE: $coords might be a single object if only 1 coord so check is_array
			if ( is_array( $coords ) )
				ksort( $coords );
	
			// get the first coord's start timestamp if needed
			if ( !$start )
				$start = key( $coords );
	
			// first check for an existing trip with this start timestamp
			if ( $trip = TripFactory::getTripByUserStart( $user->id, $start ) )
			{
				// we've already saved a trip for this user with this start time
				Util::log( "WARNING a trip for user {$user->id} starting at {$start} has already been saved" );
				Util::log( "+++++++++++++ Production: Upload Finished ++++++++++");
				header("HTTP/1.1 202 Accepted");
				$response = new stdClass;
				$response->status = 'success';
				echo json_encode( $response );
				exit;
			}
			else
				Util::log( "INFO Saving a new trip for user {$user->id} starting at {$start} with {$n_coord} coords." );
	
			// init stop to null
			$stop = null;
	
			// create a new trip, note unique compound key (user_id, start) required
			if ( $trip = TripFactory::insert( $user->id, $purpose, $notes, $start ) )
			{
				$coord = null;
				if ( $version == PROTOCOL_VERSION_3 ){					
					$stop = CoordFactory::insert_bulk( $trip->id, $coords );	
				}
				else if ( $version == PROTOCOL_VERSION_2 ){
					$stop = CoordFactory::insert_bulk_protocol_2 ( $trip->id, $coords );

				} else { // PROTOCOL_VERSION_1
					foreach ( $coords as $coord ){
						CoordFactory::insert(   $trip->id, 
												$coord->recorded, 
												$coord->latitude, 
												$coord->longitude,
												$coord->altitude, 
												$coord->speed, 
												$coord->hAccuracy, 
												$coord->vAccuracy );
					}
	
					// get the last coord's recorded => stop timestamp
					if ( $coord && isset( $coord->recorded ) )
						$stop = $coord->recorded;
				}
	
				//Util::log( "stop: {$stop}" );
	
				// update trip start, stop, n_coord
				if ( $updatedTrip = TripFactory::update( $trip->id, $stop, $n_coord ) )
				{
					Util::log( "INFO updated trip {$updatedTrip->id} stop {$stop}, n_coord {$n_coord}" );
				}
				else
					Util::log( "WARNING failed to update trip {$trip->id} stop, n_coord" );
				
				Util::log( "+++++++++++++ Production: Upload Finished ++++++++++");

				header("HTTP/1.1 201 Created");
				$response = new stdClass;
				$response->status = 'success';
				echo json_encode( $response );
				exit;
			}
			else
				Util::log( "ERROR failed to save trip, invalid trip_id" );
		} 	
	}
	else
		Util::log( "ERROR failed to save trip, invalid user" );
}
else
	Util::log( "ERROR failed to save trip, invalid device: {$device}" );

Util::log( "+++++++++++++ Production: Upload Finished with Error ++++++++++");

header("HTTP/1.1 500 Internal Server Error");
$response = new stdClass;
$response->status = 'error';
echo json_encode( $response );
exit;

