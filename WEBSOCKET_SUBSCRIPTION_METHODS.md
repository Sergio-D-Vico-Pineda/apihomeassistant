# Home Assistant WebSocket Subscription Methods

This document explains the two subscription methods implemented for real-time entity state updates.

## Method 1: Selective Entity Subscriptions (subscribe_trigger) - RECOMMENDED

### How it works:
- Creates individual subscriptions for each entity using `subscribe_trigger`
- Uses Home Assistant's trigger platform with `platform: "state"`
- Only receives updates for the specific entities you're monitoring
- More efficient for the network and Home Assistant instance

### Advantages:
✅ **High Efficiency**: Only subscribed entities send updates
✅ **Reduced Network Traffic**: No unnecessary data transmission
✅ **Lower CPU Usage**: Both client and server process less data
✅ **Scalable**: Works well with large Home Assistant installations
✅ **Selective**: Perfect control over which entities to monitor

### Disadvantages:
❌ **Multiple Subscriptions**: Creates one subscription per entity
❌ **Newer API**: Requires Home Assistant with trigger support
❌ **Complex Message Handling**: Different event structure than regular events

### Code Example:
```javascript
// Subscribe to specific entities only
wsHandler.subscribeToStates(entityIds, (stateData) => {
    console.log('Selective state update:', stateData);
    updateEntityState(stateData);
});
```

### Message Structure:
```javascript
// Subscription message
{
    "id": 123,
    "type": "subscribe_trigger",
    "trigger": {
        "platform": "state",
        "entity_id": "light.living_room"
    }
}

// Response event
{
    "id": 123,
    "type": "event",
    "event": {
        "variables": {
            "trigger": {
                "to_state": { /* new state */ },
                "from_state": { /* old state */ },
                "entity_id": "light.living_room"
            }
        }
    }
}
```

## Method 2: All-State Subscription with Client Filtering (subscribe_events) - FALLBACK

### How it works:
- Subscribes to ALL `state_changed` events from Home Assistant
- Filters events on the client-side to match your entity list
- Single subscription handles all entity updates
- Traditional Home Assistant WebSocket approach

### Advantages:
✅ **Simple Setup**: One subscription for all entities
✅ **Wide Compatibility**: Works with any Home Assistant version
✅ **Standard API**: Uses well-established `subscribe_events`
✅ **Easy Debugging**: Clear event structure and flow

### Disadvantages:
❌ **High Network Traffic**: Receives ALL state changes from HA
❌ **CPU Intensive**: Client must filter every single state change
❌ **Inefficient**: Waste bandwidth on unneeded entity updates
❌ **Not Scalable**: Performance degrades with large HA installations

### Code Example:
```javascript
// Subscribe to ALL state changes, filter client-side
wsHandler.subscribeToAllStates(entityIds, (stateData) => {
    console.log('Filtered state update:', stateData);
    updateEntityState(stateData);
});
```

### Message Structure:
```javascript
// Subscription message
{
    "id": 124,
    "type": "subscribe_events",
    "event_type": "state_changed"
}

// Response event
{
    "id": 124,
    "type": "event",
    "event": {
        "event_type": "state_changed",
        "data": {
            "entity_id": "light.living_room",
            "new_state": { /* state object */ },
            "old_state": { /* state object */ }
        }
    }
}
```

## Configuration

### In devices.php:
```javascript
// Configuration: Choose subscription method
const USE_SELECTIVE_SUBSCRIPTION = true; // Set to false for fallback method

if (USE_SELECTIVE_SUBSCRIPTION) {
    console.log('Using selective entity subscriptions (subscribe_trigger)');
    wsHandler.subscribeToStates(entityIds, callback);
} else {
    console.log('Using fallback all-state subscription with client filtering');
    wsHandler.subscribeToAllStates(entityIds, callback);
}
```

## Performance Comparison

| Aspect | Selective (subscribe_trigger) | All-State (subscribe_events) |
|--------|------------------------------|-------------------------------|
| **Network Usage** | ⭐⭐⭐⭐⭐ Minimal | ⭐⭐ High |
| **CPU Usage** | ⭐⭐⭐⭐⭐ Low | ⭐⭐ Moderate |
| **Scalability** | ⭐⭐⭐⭐⭐ Excellent | ⭐⭐ Poor |
| **Compatibility** | ⭐⭐⭐⭐ Good | ⭐⭐⭐⭐⭐ Excellent |
| **Setup Complexity** | ⭐⭐⭐ Moderate | ⭐⭐⭐⭐⭐ Simple |

## Recommendations

### Use Selective Subscriptions (subscribe_trigger) when:
- You have Home Assistant 2021.5+ (when triggers were enhanced)
- You want optimal performance
- You have a large Home Assistant installation
- Network bandwidth is a concern
- You need to monitor specific entities only

### Use All-State Subscriptions (subscribe_events) when:
- You have an older Home Assistant version
- You need maximum compatibility
- You're debugging WebSocket issues
- You have a small Home Assistant installation
- Simplicity is more important than performance

## Current Implementation Status

✅ **Selective Subscriptions**: Fully implemented with `subscribeToStates()`
✅ **All-State Subscriptions**: Fully implemented with `subscribeToAllStates()`
✅ **Auto-Unsubscribe**: Implemented with `unsubscribe()` method
✅ **Error Handling**: Both methods include comprehensive error handling
✅ **Debug Logging**: Detailed console logs for troubleshooting
✅ **Configuration Toggle**: Easy switching between methods in devices.php

## Future Enhancements

🔮 **Domain-based Subscriptions**: Subscribe to all entities of specific domains (e.g., all lights)
🔮 **Pattern Matching**: Subscribe using entity ID patterns (e.g., `sensor.temperature_*`)
🔮 **Dynamic Subscriptions**: Add/remove entity subscriptions without reconnecting
🔮 **Batched Updates**: Combine multiple state changes into single updates
🔮 **Subscription Health Monitoring**: Track subscription performance and auto-optimize
