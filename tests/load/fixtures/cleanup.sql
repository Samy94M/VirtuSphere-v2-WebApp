-- U13 fixture cleanup. Caller sets @vs_profile to S, T or L.
-- It removes only the exact binary prefix owned by that profile.
SET @vs_profile = UPPER(COALESCE(@vs_profile, ''));
SET @vs_prefix = CONCAT('loadu13_', LOWER(@vs_profile), '_');
SET @vs_valid = @vs_profile IN ('S', 'T', 'L');
SELECT IF(@vs_valid, 1, CAST('U13 profile must be S, T or L' AS UNSIGNED)) AS profile_guard;
START TRANSACTION;
DELETE FROM deploy_missions
WHERE @vs_valid AND BINARY LEFT(mission_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;
DELETE FROM deploy_packages
WHERE @vs_valid AND BINARY LEFT(package_name, CHAR_LENGTH(@vs_prefix)) = BINARY @vs_prefix;
COMMIT;
SELECT @vs_profile AS cleaned_profile, @vs_valid AS valid;
