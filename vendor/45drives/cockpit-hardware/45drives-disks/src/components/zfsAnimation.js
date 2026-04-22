export default function zfsAnimation(p5) {
  p5.zfsAnimationSteps = 24;
  p5.zfsAnimationIndex = 0;
  p5.zfsAnimationDir = 1;
  p5.zfsAnimationColors = {
    vdev_faulted: {
      start: p5.color(244, 63, 94, 100),
      end: p5.color(239, 68, 68, 200),
    },
    vdev_degraded: {
      start: p5.color(249, 115, 22, 100),
      end: p5.color(252, 211, 77, 200),
    },
    vdev_online: {
      start: p5.color(34, 197, 94, 100),
      end: p5.color(74, 222, 128, 200),
    },
    dev_offline: {
      start: p5.color(249, 115, 22, 192),
      end: p5.color(249, 115, 22, 0),
    },
    dev_online: {
      start: p5.color(74, 222, 128, 192),
      end: p5.color(34, 197, 94, 0),
    },
    dev_removed: {
      start: p5.color(239, 68, 68, 192),
      end: p5.color(244, 63, 94, 0),
    },
    dev_unavail: {
      start: p5.color(239, 68, 68, 192),
      end: p5.color(244, 63, 94, 0),
    },
    storage_active: {
      start: p5.color(59, 130, 246, 160),
      end: p5.color(96, 165, 250, 0),
    },
    storage_group: {
      start: p5.color(14, 165, 233, 120),
      end: p5.color(56, 189, 248, 200),
    },
  };

  p5.animateZpools = (x, y, w, h, steps, index, from, to, zfsAnimationDir) => {
    p5.push();
    p5.colorMode(p5.RGB);
    let stepPercent = 1.0 / steps;
    p5.noFill();
    p5.strokeWeight(3);
    let dashValue = zfsAnimationDir === 1 ? 4 : 6;
    p5.setLineDash([dashValue, dashValue]);
    p5.stroke(p5.lerpColor(from, to, index / steps));
    p5.rect(x, y, w, h);
    p5.pop();
  };

  p5.updateZfsAnimationState = () => {
    if (p5.zfsAnimationIndex > p5.zfsAnimationSteps) {
      p5.zfsAnimationDir = -1;
    } else if (p5.zfsAnimationIndex <= 0) {
      p5.zfsAnimationDir = 1;
    }
    p5.zfsAnimationIndex = p5.int(p5.zfsAnimationIndex + p5.zfsAnimationDir);
  };

  p5.setLineDash = (list) => {
    p5.drawingContext.setLineDash(list);
  };

  p5.animateVdevs = (x, y, w, h, steps, index, from, to) => {
    p5.push();
    p5.colorMode(p5.RGB);
    p5.noStroke();
    let stepPercent = 1.0 / steps;
    p5.fill(p5.lerpColor(from, to, index / steps));
    p5.rect(x, y, w, h);
    p5.pop();
  };

  p5.showStorageActivity = (cd, animationInfo, diskLocations, y_offset = 0) => {
    const currentDisk = animationInfo?.animation_disks?.[cd];
    const group = animationInfo?.animation_groups?.[currentDisk?.group] || [];
    if (!currentDisk || group.length === 0) {
      return false;
    }

    p5.updateZfsAnimationState();
    group.forEach((dsk) => {
      const dsk_idx = diskLocations.findIndex((loc) => loc.BAY === dsk.name);
      if (dsk_idx < 0 || !diskLocations[dsk_idx]?.image) return;

      const loc = diskLocations[dsk_idx];
      if (dsk.name === cd) {
        p5.animateVdevs(
          loc.x,
          loc.y,
          loc.image.width,
          loc.image.height + y_offset,
          p5.zfsAnimationSteps,
          p5.zfsAnimationIndex,
          p5.zfsAnimationColors.storage_active.start,
          p5.zfsAnimationColors.storage_active.end
        );
        return;
      }

      p5.animateZpools(
        loc.x,
        loc.y,
        loc.image.width,
        loc.image.height + y_offset,
        p5.zfsAnimationSteps,
        p5.zfsAnimationIndex,
        p5.zfsAnimationColors.storage_group.start,
        p5.zfsAnimationColors.storage_group.end,
        p5.zfsAnimationDir
      );
    });

    return true;
  };

  p5.showAnimations = (cd, zfsInfo, diskLocations, y_offset = 0) => {
    if (zfsInfo.zfs_installed) {
      //zfs is installed
      if (Array.isArray(zfsInfo.zpools) && zfsInfo.zfs_disks && zfsInfo.zfs_disks[cd]) {
        //current disk is member of a zpool
        let pool_idx = zfsInfo.zpools.findIndex(
          (pool) => pool.name === zfsInfo.zfs_disks[cd].zpool_name
        );
        if (pool_idx >= 0 && zfsInfo.zpools[pool_idx]) {
          //we have the index of the zpool to which the current disk belongs
          p5.updateZfsAnimationState();
          //animate each disk within the same vdev as the current
          zfsInfo.zpools[pool_idx].vdevs[
            zfsInfo.zfs_disks[cd].vdev_idx
          ].disks.forEach((dsk) => {
            //look at each disk in the same vdev as the current disk
            let dsk_idx = diskLocations.findIndex(
              (loc) => loc.BAY === dsk.name
            );
            if (typeof dsk_idx != "undefined" && diskLocations[dsk_idx]) {
              //we have found the index of the disk within the diskLocations object.
              //set the fill color accordingly.
              let from = p5.color(255, 255, 255, 100);
              let to = p5.color(0, 0, 0, 0);
              if (dsk.state === "ONLINE") {
                from = p5.zfsAnimationColors.dev_online.start;
                to = p5.zfsAnimationColors.dev_online.end;
              } else if (dsk.state === "OFFLINE") {
                from = p5.zfsAnimationColors.dev_offline.start;
                to = p5.zfsAnimationColors.dev_offline.end;
              } else if (dsk.state === "REMOVED") {
                from = p5.zfsAnimationColors.dev_removed.start;
                to = p5.zfsAnimationColors.dev_removed.end;
              } else if (dsk.state === "UNAVAIL") {
                from = p5.zfsAnimationColors.dev_unavail.start;
                to = p5.zfsAnimationColors.dev_unavail.end;
              }
              //animate each disk within the vdev.
              p5.animateVdevs(
                diskLocations[dsk_idx].x,
                diskLocations[dsk_idx].y,
                diskLocations[dsk_idx].image.width,
                diskLocations[dsk_idx].image.height + y_offset,
                p5.zfsAnimationSteps,
                p5.zfsAnimationIndex,
                from,
                to
              );
            }
          });
          zfsInfo.zpools[pool_idx].vdevs.forEach((vdev) => {
            //look at each vdev in same zpool as the current disk
            vdev.disks.forEach((dsk) => {
              //look at each disk in the vdev
              let dsk_idx = diskLocations.findIndex(
                (loc) => loc.BAY === dsk.name
              );
              if (typeof dsk_idx != "undefined" && diskLocations[dsk_idx]) {
                //we have found the index of the disk within the diskLocations object.
                //set the fill color accordingly.
                let from = p5.color(255, 255, 255, 100);
                let to = p5.color(0, 0, 0, 0);
                if (vdev.state === "ONLINE") {
                  from = p5.zfsAnimationColors.vdev_online.start;
                  to = p5.zfsAnimationColors.vdev_online.end;
                } else if (vdev.state === "DEGRADED") {
                  from = p5.zfsAnimationColors.vdev_degraded.start;
                  to = p5.zfsAnimationColors.vdev_degraded.end;
                } else if (vdev.state === "FAULTED") {
                  from = p5.zfsAnimationColors.vdev_faulted.start;
                  to = p5.zfsAnimationColors.vdev_faulted.end;
                } else if (vdev.state === "UNAVAIL") {
                  from = p5.zfsAnimationColors.vdev_faulted.start;
                  to = p5.zfsAnimationColors.vdev_faulted.end;
                }
                //animate each disk within the zpool.
                p5.animateZpools(
                  diskLocations[dsk_idx].x,
                  diskLocations[dsk_idx].y,
                  diskLocations[dsk_idx].image.width,
                  diskLocations[dsk_idx].image.height + y_offset,
                  p5.zfsAnimationSteps,
                  p5.zfsAnimationIndex,
                  from,
                  to,
                  p5.zfsAnimationDir
                );
              }
            });
          });
          return true;
        }
      }
    }
    return p5.showStorageActivity(cd, zfsInfo, diskLocations, y_offset);
  };

  p5.showZfs = p5.showAnimations;
}
