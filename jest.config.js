const defaults = require( '@wordpress/scripts/config/jest-unit.config' );
module.exports = { ...defaults, testMatch: [ '**/tests/js/**/*.test.js' ] };
