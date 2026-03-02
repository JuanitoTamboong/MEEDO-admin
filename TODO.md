# TODO - Fix Stall Display in Tenant Registration

## Task: Show only available stalls in tenant registration page

### Issue:
- Currently tenant-register.php shows ALL stalls (both available and occupied)
- User wants it to show only stalls with status='available' in the database

### Plan:
- [x] Analyze existing code in admin/manage-stalls.php and tenant-register.php
- [ ] Modify tenant-register.php SQL query to filter by status='available'
- [ ] Update PHP code to only show available stalls
- [ ] Update JavaScript to handle only available stalls
- [ ] Test the changes

### Files to Edit:
- tenant-register.php
  - Update SQL query to filter: WHERE s.status = 'available' AND t.id IS NULL
  - Remove occupied stall display logic since only available will show
