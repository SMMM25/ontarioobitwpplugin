
import { useState, useEffect } from "react";
import { cn } from "@/lib/utils";
import ObituaryCard from "./ObituaryCard";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { 
  Search, 
  Calendar, 
  MapPin,
  X
} from "lucide-react";
import { mockObituaries } from "@/utils/dataUtils";

const ObituaryList = () => {
  const [searchTerm, setSearchTerm] = useState("");
  const [locationFilter, setLocationFilter] = useState("");
  const [isSearchFocused, setIsSearchFocused] = useState(false);
  const [obituaries, setObituaries] = useState(mockObituaries);
  const [filteredObituaries, setFilteredObituaries] = useState(mockObituaries);
  const [isLoading, setIsLoading] = useState(true);

  // Simulate loading data
  useEffect(() => {
    const timer = setTimeout(() => {
      setIsLoading(false);
    }, 1000);

    return () => clearTimeout(timer);
  }, []);

  // Filter obituaries when search or location filter changes
  useEffect(() => {
    const filtered = obituaries.filter(obit => {
      const matchesSearch = searchTerm === "" || 
        obit.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        obit.description.toLowerCase().includes(searchTerm.toLowerCase());
      
      const matchesLocation = locationFilter === "" || 
        obit.location.toLowerCase().includes(locationFilter.toLowerCase());
      
      return matchesSearch && matchesLocation;
    });
    
    setFilteredObituaries(filtered);
  }, [searchTerm, locationFilter, obituaries]);

  const clearFilters = () => {
    setSearchTerm("");
    setLocationFilter("");
  };

  // Unique locations for the filter
  const uniqueLocations = Array.from(
    new Set(obituaries.map(obit => obit.location))
  ).sort();

  return (
    <div className="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div className="sticky top-20 z-10 bg-background/80 backdrop-blur-sm py-4 border-b border-border/30">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
          <div className={cn(
            "relative flex-1 transition-all duration-300",
            isSearchFocused ? "flex-grow" : ""
          )}>
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder="Search obituaries..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              onFocus={() => setIsSearchFocused(true)}
              onBlur={() => setIsSearchFocused(false)}
              className="pl-10 pr-4 py-2 h-10 bg-secondary/50 border-none shadow-none focus-visible:ring-transparent"
            />
            {searchTerm && (
              <Button
                variant="ghost"
                size="icon"
                className="absolute right-0 top-0 h-10 w-10 text-muted-foreground hover:text-foreground"
                onClick={() => setSearchTerm("")}
              >
                <X className="h-4 w-4" />
              </Button>
            )}
          </div>
          
          <div className="relative min-w-[180px]">
            <MapPin className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <select
              value={locationFilter}
              onChange={(e) => setLocationFilter(e.target.value)}
              className="w-full h-10 pl-10 pr-4 py-2 appearance-none bg-secondary/50 border-none rounded-md shadow-none focus:ring-2 focus:ring-primary text-sm"
            >
              <option value="">All Locations</option>
              {uniqueLocations.map(location => (
                <option key={location} value={location}>{location}</option>
              ))}
            </select>
          </div>
          
          {(searchTerm || locationFilter) && (
            <Button
              variant="ghost"
              size="sm"
              onClick={clearFilters}
              className="text-sm text-muted-foreground hover:text-foreground"
            >
              Clear Filters
            </Button>
          )}
        </div>
        
        <div className="mt-4 flex items-center justify-between">
          <div className="flex items-center space-x-2 text-sm text-muted-foreground">
            <Calendar className="h-4 w-4" />
            <span>Last updated: Today at 9:00 AM</span>
          </div>
          
          <p className="text-sm text-muted-foreground">
            {filteredObituaries.length} {filteredObituaries.length === 1 ? "obituary" : "obituaries"}
          </p>
        </div>
      </div>
      
      {isLoading ? (
        <div className="py-12">
          <div className="grid grid-cols-1 gap-6">
            {[1, 2, 3].map(i => (
              <div key={i} className="h-64 rounded-lg bg-muted/30 animate-pulse" />
            ))}
          </div>
        </div>
      ) : (
        <>
          {filteredObituaries.length === 0 ? (
            <div className="py-20 text-center">
              <h3 className="text-lg font-medium text-foreground">No obituaries found</h3>
              <p className="mt-2 text-sm text-muted-foreground">
                Try adjusting your search or filters to find what you're looking for.
              </p>
              <Button
                variant="secondary"
                size="sm"
                onClick={clearFilters}
                className="mt-4"
              >
                Clear All Filters
              </Button>
            </div>
          ) : (
            <div className="py-8">
              <div className="grid grid-cols-1 gap-6 animate-fade-in">
                {filteredObituaries.map((obituary, index) => (
                  <div 
                    key={obituary.id}
                    className="animate-slide-up"
                    style={{ animationDelay: `${index * 0.05}s` }}
                  >
                    <ObituaryCard obituary={obituary} />
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
};

export default ObituaryList;
