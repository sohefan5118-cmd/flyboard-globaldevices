INSERT INTO settings(key,value)
VALUES('device_window_minutes','2'::jsonb)
ON CONFLICT DO NOTHING;
