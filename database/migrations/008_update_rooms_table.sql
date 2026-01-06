-- Migration: Update Rooms Table
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS floor VARCHAR(50);
ALTER TABLE rooms ADD COLUMN IF NOT EXISTS ward VARCHAR(100);

-- Optional: Add index for faster searching by ward/floor
CREATE INDEX IF NOT EXISTS idx_rooms_ward ON rooms(ward);
CREATE INDEX IF NOT EXISTS idx_rooms_floor ON rooms(floor);
