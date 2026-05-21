<?php

Helpers::checkRequest("GET");
if (!Helpers::verifyProxyHmacV2Request()) {
	status_header(401);
	echo json_encode(["status" => "unauthorized"]);
	exit;
}
echo json_encode(["status" => "active"]);
