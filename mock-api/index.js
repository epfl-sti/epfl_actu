var express = require('express'),
    json = require('express-json'),
    mock_actus_fr = require("./mock_actus_fr");

var servingPort = process.env.MOCK_API_PORT || 3000;
var app = express()
  .use(json())
  .use(function (req, res) {
    res.json(mock_actus_fr);
  })
  .listen(servingPort, function() {
      console.log("Listening on port " + servingPort);
});
